using System;
using System.ComponentModel;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;

namespace Horus.Testing
{
    // No shell, pipes, Job Object, polling for EOF or process termination.
    public static class NativeCapture
    {
        [StructLayout(LayoutKind.Sequential)] struct SECURITY_ATTRIBUTES { public int length; public IntPtr descriptor; public int inherit; }
        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] struct STARTUPINFO {
            public int size; public string reserved, desktop, title; public int x,y,xSize,ySize,xChars,yChars,fill,flags;
            public short show,reservedSize; public IntPtr reservedBytes,input,output,error;
        }
        [StructLayout(LayoutKind.Sequential)] struct STARTUPINFOEX { public STARTUPINFO info; public IntPtr attributes; }
        [StructLayout(LayoutKind.Sequential)] struct PROCESS_INFORMATION { public IntPtr process,thread; public uint pid,tid; }
        [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern IntPtr CreateFile(string path,uint access,uint share,ref SECURITY_ATTRIBUTES security,uint disposition,uint flags,IntPtr template);
        [DllImport("kernel32.dll", SetLastError=true)] static extern bool CloseHandle(IntPtr handle);
        [DllImport("kernel32.dll", SetLastError=true)] static extern bool InitializeProcThreadAttributeList(IntPtr list,int count,int flags,ref IntPtr size);
        [DllImport("kernel32.dll", SetLastError=true)] static extern bool UpdateProcThreadAttribute(IntPtr list,uint flags,IntPtr attribute,IntPtr value,IntPtr size,IntPtr previous,IntPtr returned);
        [DllImport("kernel32.dll")] static extern void DeleteProcThreadAttributeList(IntPtr list);
        [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern bool CreateProcess(string application,StringBuilder command,IntPtr processSecurity,IntPtr threadSecurity,bool inherit,uint flags,IntPtr environment,string directory,ref STARTUPINFOEX startup,out PROCESS_INFORMATION process);
        [DllImport("kernel32.dll", SetLastError=true)] static extern uint WaitForSingleObject(IntPtr handle,uint milliseconds);
        [DllImport("kernel32.dll", SetLastError=true)] static extern bool GetExitCodeProcess(IntPtr process,out uint code);
        static readonly IntPtr Invalid = new IntPtr(-1);
        public sealed class Result {
            public string Outcome, StdoutPath, StderrPath, ExceptionType, ExceptionMessage;
            public uint? ExitCode, ProcessId;
            public long DurationMilliseconds;
        }
        static string Quote(string value) {
            var b=new StringBuilder("\""); int slash=0;
            foreach(char c in value) {
                if(c=='\0') throw new ArgumentException("NUL is not a native argument");
                if(c=='\\') { slash++; continue; }
                if(c=='\"') { b.Append('\\',slash*2+1); b.Append(c); }
                else { b.Append('\\',slash); b.Append(c); }
                slash=0;
            }
            b.Append('\\',slash*2); return b.Append('"').ToString();
        }
        public static Result Run(string executable,string[] arguments,string directory,string stdoutPath,string stderrPath,uint timeoutMilliseconds) {
            var r=new Result { StdoutPath=stdoutPath, StderrPath=stderrPath };
            var clock=System.Diagnostics.Stopwatch.StartNew();
            IntPtr output=Invalid,error=Invalid,input=Invalid,list=IntPtr.Zero,handles=IntPtr.Zero;
            PROCESS_INFORMATION pi=new PROCESS_INFORMATION(); bool listInitialized=false;
            try {
                if(!Path.IsPathRooted(executable)||!Path.IsPathRooted(directory)||!Path.IsPathRooted(stdoutPath)||!Path.IsPathRooted(stderrPath)) throw new ArgumentException("Absolute paths required");
                var sa=new SECURITY_ATTRIBUTES {length=Marshal.SizeOf(typeof(SECURITY_ATTRIBUTES)),inherit=1};
                // CREATE_NEW prevents overwriting evidence. Shares allow concurrent snapshots, not deletion.
                output=CreateFile(stdoutPath,0x40000000,3,ref sa,1,0x80,IntPtr.Zero);
                if(output==Invalid) throw new Win32Exception(Marshal.GetLastWin32Error());
                error=CreateFile(stderrPath,0x40000000,3,ref sa,1,0x80,IntPtr.Zero);
                if(error==Invalid) throw new Win32Exception(Marshal.GetLastWin32Error());
                input=CreateFile("NUL",0x80000000,3,ref sa,3,0x80,IntPtr.Zero);
                if(input==Invalid) throw new Win32Exception(Marshal.GetLastWin32Error());
                IntPtr size=IntPtr.Zero; InitializeProcThreadAttributeList(IntPtr.Zero,1,0,ref size);
                list=Marshal.AllocHGlobal(size); if(!InitializeProcThreadAttributeList(list,1,0,ref size)) throw new Win32Exception(Marshal.GetLastWin32Error());
                listInitialized=true; handles=Marshal.AllocHGlobal(IntPtr.Size*3);
                Marshal.WriteIntPtr(handles,0,input); Marshal.WriteIntPtr(handles,IntPtr.Size,output); Marshal.WriteIntPtr(handles,IntPtr.Size*2,error);
                // Only these three handles are inherited, never unrelated handles of this host.
                if(!UpdateProcThreadAttribute(list,0,new IntPtr(0x20002),handles,new IntPtr(IntPtr.Size*3),IntPtr.Zero,IntPtr.Zero)) throw new Win32Exception(Marshal.GetLastWin32Error());
                var startup=new STARTUPINFOEX();startup.info.size=Marshal.SizeOf(typeof(STARTUPINFOEX));startup.info.flags=0x100;
                startup.info.input=input;startup.info.output=output;startup.info.error=error;startup.attributes=list;
                var command=new StringBuilder(Quote(executable)); foreach(string arg in arguments) command.Append(' ').Append(Quote(arg));
                if(!CreateProcess(executable,command,IntPtr.Zero,IntPtr.Zero,true,0x08080000,IntPtr.Zero,directory,ref startup,out pi)) throw new Win32Exception(Marshal.GetLastWin32Error());
                r.ProcessId=pi.pid;
                uint wait=WaitForSingleObject(pi.process,timeoutMilliseconds);
                if(wait==0x102) r.Outcome="TimedOut"; // Root may still run. Never terminate it or its descendants here.
                else if(wait==0) { uint code; if(!GetExitCodeProcess(pi.process,out code)) throw new Win32Exception(Marshal.GetLastWin32Error());r.ExitCode=code;r.Outcome="Exited"; }
                else throw new Win32Exception(Marshal.GetLastWin32Error());
            } catch(Exception ex) { r.Outcome="ExecutionError";r.ExceptionType=ex.GetType().FullName;r.ExceptionMessage=ex.Message; }
            finally {
                if(pi.thread!=IntPtr.Zero) CloseHandle(pi.thread);if(pi.process!=IntPtr.Zero) CloseHandle(pi.process);
                if(output!=Invalid) CloseHandle(output);if(error!=Invalid) CloseHandle(error);if(input!=Invalid) CloseHandle(input);
                if(listInitialized) DeleteProcThreadAttributeList(list);
                if(list!=IntPtr.Zero) Marshal.FreeHGlobal(list);if(handles!=IntPtr.Zero) Marshal.FreeHGlobal(handles);
                clock.Stop();r.DurationMilliseconds=clock.ElapsedMilliseconds;
            }
            return r;
        }
        public static bool WritersClosed(string path) {
            try { using(var stream=new FileStream(path,FileMode.Open,FileAccess.Read,FileShare.None)) return true; }
            catch(IOException) { return false; }
        }
        public sealed class Snapshot {
            public byte[] Output, Error;
            public bool StreamsClosed;
        }
        static byte[] ReadLength(FileStream stream) {
            // Freeze the boundary: a descendant can append forever without extending this read.
            long length=stream.Length;
            if(length>Int32.MaxValue) throw new IOException("Snapshot exceeds supported in-memory size; raw files retained");
            var bytes=new byte[(int)length];int offset=0;
            while(offset<bytes.Length) {
                int count=stream.Read(bytes,offset,bytes.Length-offset);
                if(count==0) throw new IOException("Capture file truncated during snapshot");
                offset+=count;
            }
            return bytes;
        }
        public static Snapshot ReadPair(string output,string error) {
            try {
                // Both locks span BOTH reads. No check-then-read race can label a partial snapshot complete.
                using(var a=new FileStream(output,FileMode.Open,FileAccess.Read,FileShare.None))
                using(var b=new FileStream(error,FileMode.Open,FileAccess.Read,FileShare.None))
                    return new Snapshot {Output=ReadLength(a),Error=ReadLength(b),StreamsClosed=true};
            } catch(IOException) {
                using(var a=new FileStream(output,FileMode.Open,FileAccess.Read,FileShare.ReadWrite))
                using(var b=new FileStream(error,FileMode.Open,FileAccess.Read,FileShare.ReadWrite))
                    return new Snapshot {Output=ReadLength(a),Error=ReadLength(b),StreamsClosed=false};
            }
        }
        public static string DecodeUtf8(byte[] bytes) {
            int skip=bytes.Length>=3 && bytes[0]==239 && bytes[1]==187 && bytes[2]==191 ? 3 : 0;
            return new UTF8Encoding(false,true).GetString(bytes,skip,bytes.Length-skip);
        }
        public static string SnapshotUtf8(string path) {
            using(var stream=new FileStream(path,FileMode.Open,FileAccess.Read,FileShare.ReadWrite))
                return DecodeUtf8(ReadLength(stream));
        }
    }
}
