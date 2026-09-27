"""Read the ESP32 serial log for a while, stop early once a heartbeat result is seen."""
import sys, time, serial

port, secs = sys.argv[1], float(sys.argv[2])
end = time.time() + secs
ser = None
while time.time() < end and ser is None:
    try:
        ser = serial.Serial(port, 115200, timeout=1)
    except Exception:
        time.sleep(1)  # port reappears after the USB reset
if ser is None:
    print("could not open", port); sys.exit(1)

seen_ok = False
while time.time() < end:
    try:
        line = ser.readline().decode("utf-8", "replace").rstrip()
    except Exception as e:
        print("serial error:", e); time.sleep(1); continue
    if line:
        print(line, flush=True)
        if "Heartbeat OK" in line:
            seen_ok = True
        if seen_ok and ("Heartbeat OK" in line):
            end = min(end, time.time() + 5)
        if "Heartbeat failed" in line:
            end = min(end, time.time() + 20)
