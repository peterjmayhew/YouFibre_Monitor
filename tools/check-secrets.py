"""Pre-push safety check: make sure no private values are in files git will upload.

Usage (from the repo root):   python tools/check-secrets.py

Checks every tracked and staged file for:
  * the values in ESP32/YouFibreMonitor/secrets.h (Wi-Fi name/password, token, site URL)
  * any extra strings listed one per line in tools/private-patterns.txt
    (git-ignored - put your domain, home IP, personal email etc. there)
Values are never printed. Exit code 1 if anything is found.
"""
import os, re, subprocess, sys

root = subprocess.run(["git", "rev-parse", "--show-toplevel"], capture_output=True, text=True).stdout.strip() or "."
os.chdir(root)

checks = {}
sec = "ESP32/YouFibreMonitor/secrets.h"
if os.path.exists(sec):
    for k, v in re.findall(r'#define\s+(\w+)\s+"([^"]*)"', open(sec, encoding="utf-8").read()):
        if len(v) >= 4 and "example" not in v.lower() and not v.startswith(("Your", "paste-")):
            checks[f"secrets.h {k}"] = v
else:
    print("note: secrets.h not found - only checking private-patterns.txt")

pp = "tools/private-patterns.txt"
if os.path.exists(pp):
    for i, line in enumerate(open(pp, encoding="utf-8"), 1):
        line = line.strip()
        if line and not line.startswith("#"):
            checks[f"private-patterns.txt line {i}"] = line

files = [f for f in subprocess.run(["git", "ls-files", "-z", "--cached"], capture_output=True).stdout.decode().split("\0") if f]
leaks = []
for f in files:
    try:
        text = open(f, encoding="utf-8", errors="replace").read().lower()
    except OSError:
        continue
    for name, val in checks.items():
        if val.lower() in text:
            leaks.append((f, name))

print(f"checked {len(files)} files against {len(checks)} private values")
if leaks:
    for f, name in leaks:
        print(f"  LEAK: {name} found in {f}")
    print("DO NOT PUSH")
    sys.exit(1)
print("SAFE - no private values found")
