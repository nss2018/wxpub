#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
阿里云 SWAS 云助手推送工具（免 SSH，走 OpenAPI RunCommand）
源自 hunliacard/scripts/deploy_via_cloudassistant.py，通用化改造。

用法：
  python3 push.py sync                    # 推送 wxpub 全部文件到 /www/wxpub/ 并 php -l 校验
  python3 push.py put <本地> <远程绝对路径> [本地2 远程2 ...]   # 推送任意文件
  python3 push.py run '<shell 命令>'       # 在服务器执行命令并回显输出
"""
import base64
import hashlib
import hmac
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

# ============ 阿里云凭证（从环境变量读取，不要硬编码） ============
AK_ID = os.environ.get("ALIYUN_AK_ID", "")
AK_SECRET = os.environ.get("ALIYUN_AK_SECRET", "")
if not AK_ID or not AK_SECRET:
    print("请先设置环境变量 ALIYUN_AK_ID / ALIYUN_AK_SECRET（阿里云 AccessKey）", file=sys.stderr)
    sys.exit(2)

# ============ 服务器信息 ============
REGION = "cn-zhangjiakou"
INSTANCE_ID = os.environ.get("ALIYUN_SWAS_INSTANCE_ID", "")  # 你的 SWAS 实例 ID
ENDPOINT = "swas.cn-zhangjiakou.aliyuncs.com"
API_VERSION = "2020-06-01"

REMOTE_DIR = "/www/wxpub"
LOCAL_DIR = os.path.dirname(os.path.abspath(__file__))

# sync 模式推送的文件清单：(本地相对路径, 远程文件名)
SYNC_FILES = [
    ("wxpub.php", "wxpub.php"),
    ("Theme.php", "Theme.php"),
    ("Markdown.php", "Markdown.php"),
    ("Cover.php", "Cover.php"),
    ("NewspicSlide.php", "NewspicSlide.php"),
    ("WeChatClient.php", "WeChatClient.php"),
    ("config.php", "config.php"),
    ("cover.png", "cover.png"),
]

CHUNK_SIZE = 10000  # base64 字符数，确保单条 RunCommand 脚本 < 16KB


def _percent_encode(s):
    return urllib.parse.quote(str(s), safe="~")


def _sign(params):
    sorted_params = sorted(params.items(), key=lambda x: x[0])
    canonical = "&".join("{}={}".format(_percent_encode(k), _percent_encode(v)) for k, v in sorted_params)
    string_to_sign = "POST&%2F&" + _percent_encode(canonical)
    key = (AK_SECRET + "&").encode("utf-8")
    sig = hmac.new(key, string_to_sign.encode("utf-8"), hashlib.sha1).digest()
    return base64.b64encode(sig).decode("utf-8")


def call_api(action, biz_params):
    params = {
        "Action": action, "Version": API_VERSION, "Format": "JSON",
        "AccessKeyId": AK_ID, "SignatureMethod": "HMAC-SHA1",
        "SignatureVersion": "1.0", "SignatureNonce": str(int(time.time() * 1000) + os.getpid()),
        "Timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "RegionId": REGION,
    }
    params.update(biz_params)
    params["Signature"] = _sign(params)
    body = "&".join("{}={}".format(_percent_encode(k), _percent_encode(v)) for k, v in params.items())
    req = urllib.request.Request(
        "https://{}/".format(ENDPOINT),
        data=body.encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        err = e.read().decode("utf-8", errors="replace")
        print("  [HTTP {}] {}".format(e.code, err), file=sys.stderr)
        return None


def _decode_output(s):
    """云助手 Output 为 base64，解出可读文本；不是合法 base64 则原样返回"""
    s = s or ""
    try:
        d = base64.b64decode(s.strip(), validate=True)
        t = d.decode("utf-8")
        if all(c.isprintable() or c in "\n\r\t" for c in t):
            return t
    except Exception:
        pass
    return s


def run_command(name, script, wait=True, timeout=180):
    """执行远程命令。wait=True 时阻塞等待并返回 (status, output, exit_code)"""
    result = call_api("RunCommand", {
        "InstanceId": INSTANCE_ID,
        "Name": name,
        "Type": "RunShellScript",
        "CommandContent": script,
        "Timeout": "120",
    })
    if not result or "InvokeId" not in result:
        print("  [FAIL] RunCommand 失败: {}".format(result), file=sys.stderr)
        return None, "", -1
    invoke_id = result["InvokeId"]
    if not wait:
        return invoke_id, "", 0
    return wait_command(invoke_id, timeout)


def wait_command(invoke_id, timeout=180):
    start = time.time()
    while time.time() - start < timeout:
        result = call_api("DescribeCommandInvocations", {
            "InvokeId": invoke_id,
            "InstanceId": INSTANCE_ID,
        })
        if result:
            invs = result.get("CommandInvocations", [])
            if invs:
                inv = invs[0]
                status = inv.get("InvocationStatus", "")
                instances = inv.get("InvokeInstances", [])
                output, exit_code = "", -1
                if instances:
                    output = _decode_output(instances[0].get("Output", ""))
                    exit_code = instances[0].get("ExitCode", -1)
                if status in ("Success", "Failed", "Error", "Canceled", "Timeout"):
                    return status, output, exit_code
        time.sleep(3)
    return "Timeout", "", -1


def upload_file(local_abs, remote_abs):
    """分块 base64 上传单个文件到远程绝对路径"""
    if not os.path.isfile(local_abs):
        print("  [SKIP] 本地文件不存在: {}".format(local_abs))
        return False

    with open(local_abs, "rb") as f:
        content = f.read()
    b64 = base64.b64encode(content).decode("utf-8")
    chunks = [b64[i:i + CHUNK_SIZE] for i in range(0, len(b64), CHUNK_SIZE)]

    b64_tmp = "/tmp/{}.b64".format(re.sub(r"[/.\s]", "_", remote_abs))
    print("  上传 {} ({} bytes, {} 块)".format(os.path.basename(local_abs), len(content), len(chunks)))

    # 清空临时文件
    status, _, code = run_command("push-clear", "rm -f {t}; touch {t}".format(t=b64_tmp))
    if status != "Success":
        print("    [FAIL] 清理临时文件失败: {}".format(status))
        return False

    # 分块追加（base64 不含单引号，printf 单引号包裹安全）
    for i, chunk in enumerate(chunks):
        script = "printf '%s' '{}' >> {}".format(chunk, b64_tmp)
        status, _, code = run_command("push-chunk-{}".format(i), script)
        if status != "Success" or code != 0:
            print("    [FAIL] 块 {} 失败: {} code={}".format(i, status, code))
            return False
        if (i + 1) % 5 == 0 or i == len(chunks) - 1:
            print("    {}/{} 块完成".format(i + 1, len(chunks)))

    # 解码落位
    remote_dir = os.path.dirname(remote_abs)
    script = ("mkdir -p {d} && base64 -d {t} > {f} && rm -f {t} && wc -c {f}"
              .format(d=remote_dir, t=b64_tmp, f=remote_abs))
    status, out, code = run_command("push-decode", script)
    if out.strip():
        print("    " + out.strip().splitlines()[-1])
    return status == "Success" and code == 0


def cmd_sync():
    print("=" * 56)
    print("sync: 本地 {} -> 服务器 {}:{}".format(LOCAL_DIR, INSTANCE_ID, REMOTE_DIR))
    print("=" * 56)
    for rel, name in SYNC_FILES:
        if not upload_file(os.path.join(LOCAL_DIR, rel), "{}/{}".format(REMOTE_DIR, name)):
            print("\n[ABORT] {} 推送失败".format(rel))
            sys.exit(1)
    # 语法校验
    php_files = " ".join("{}/{}".format(REMOTE_DIR, n) for _, n in SYNC_FILES if n.endswith(".php"))
    status, out, code = run_command("push-lint", "php -l {}".format(php_files))
    print("\n----- php -l -----")
    print(out or "(无输出)")
    print("状态: {} 退出码: {}".format(status, code))
    sys.exit(0 if status == "Success" and code == 0 else 1)


def cmd_put(args):
    if len(args) < 2 or len(args) % 2 != 0:
        print("用法: python3 push.py put <本地1> <远程绝对路径1> [本地2 远程2 ...]")
        sys.exit(2)
    for i in range(0, len(args), 2):
        local, remote = os.path.abspath(args[i]), args[i + 1]
        if not upload_file(local, remote):
            print("[ABORT] {} 推送失败".format(local))
            sys.exit(1)
    print("全部推送完成 ✓")


def cmd_run(script):
    status, out, code = run_command("push-run", script)
    if out:
        print(out)
    print("---- 状态: {} 退出码: {} ----".format(status, code))
    sys.exit(0 if status == "Success" and code == 0 else 1)


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(2)
    cmd = sys.argv[1]
    if cmd == "sync":
        cmd_sync()
    elif cmd == "put":
        cmd_put(sys.argv[2:])
    elif cmd == "run":
        if len(sys.argv) < 3:
            print("用法: python3 push.py run '<shell 命令>'")
            sys.exit(2)
        cmd_run(sys.argv[2])
    else:
        print("未知命令: {}（支持 sync / put / run）".format(cmd))
        sys.exit(2)


if __name__ == "__main__":
    main()
