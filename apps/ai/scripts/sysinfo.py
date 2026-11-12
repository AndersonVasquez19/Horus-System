"""Memoria del proceso sin dependencias extra (Windows: psapi; Linux/macOS: resource)."""

from __future__ import annotations

import sys


def process_memory_mb(pid: int | None = None) -> dict[str, float] | None:
    """Conjunto de trabajo actual y pico (MB) de este proceso o de `pid` (solo Windows)."""
    if sys.platform == "win32":
        import ctypes
        from ctypes import wintypes

        class Counters(ctypes.Structure):
            _fields_ = [
                ("cb", wintypes.DWORD),
                ("PageFaultCount", wintypes.DWORD),
                ("PeakWorkingSetSize", ctypes.c_size_t),
                ("WorkingSetSize", ctypes.c_size_t),
                ("QuotaPeakPagedPoolUsage", ctypes.c_size_t),
                ("QuotaPagedPoolUsage", ctypes.c_size_t),
                ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t),
                ("QuotaNonPagedPoolUsage", ctypes.c_size_t),
                ("PagefileUsage", ctypes.c_size_t),
                ("PeakPagefileUsage", ctypes.c_size_t),
            ]

        kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
        psapi = ctypes.WinDLL("psapi", use_last_error=True)
        kernel32.OpenProcess.restype = wintypes.HANDLE
        kernel32.GetCurrentProcess.restype = wintypes.HANDLE
        kernel32.CloseHandle.argtypes = [wintypes.HANDLE]
        psapi.GetProcessMemoryInfo.argtypes = [wintypes.HANDLE, ctypes.POINTER(Counters), wintypes.DWORD]
        psapi.GetProcessMemoryInfo.restype = wintypes.BOOL
        if pid is None:
            handle, close = kernel32.GetCurrentProcess(), False
        else:
            handle = kernel32.OpenProcess(0x0400 | 0x0010, False, pid)  # QUERY_INFORMATION | VM_READ
            close = True
            if not handle:
                return None
        counters = Counters()
        counters.cb = ctypes.sizeof(Counters)
        ok = psapi.GetProcessMemoryInfo(handle, ctypes.byref(counters), counters.cb)
        if close:
            kernel32.CloseHandle(handle)
        if not ok:
            return None
        mb = 1024 * 1024
        return {
            "working_set": round(counters.WorkingSetSize / mb, 1),
            "peak_working_set": round(counters.PeakWorkingSetSize / mb, 1),
            "private": round(counters.PagefileUsage / mb, 1),
        }
    if pid is not None:
        return None
    import resource

    peak = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    return {"peak_rss": round(peak / (1024 * 1024 if sys.platform == "darwin" else 1024), 1)}
