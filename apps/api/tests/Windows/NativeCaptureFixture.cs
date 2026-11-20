using System;
using System.IO;
using System.Text;
using System.Diagnostics;
using System.Runtime.InteropServices;
class CaptureFixture {
 [StructLayout(LayoutKind.Sequential,CharSet=CharSet.Unicode)] struct SI {public int cb;public string reserved,desktop,title;public int x,y,cx,cy,xc,yc,fill,flags;public short show,size;public IntPtr bytes,input,output,error;}
 [StructLayout(LayoutKind.Sequential)] struct PI {public IntPtr process,thread;public uint pid,tid;}
 [DllImport("kernel32.dll")] static extern IntPtr GetStdHandle(int n);
 [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)] static extern bool CreateProcess(string app,StringBuilder cmd,IntPtr ps,IntPtr ts,bool inherit,uint flags,IntPtr env,string cwd,ref SI si,out PI pi);
 [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr h);
 static int Main(string[] a) {
  Console.OutputEncoding=new UTF8Encoding(false);
  File.WriteAllText(a[1]+".root-pid",Process.GetCurrentProcess().Id.ToString());
  if(a[0]=="child") {File.WriteAllText(a[1]+".child-pid",Process.GetCurrentProcess().Id.ToString());System.Threading.Thread.Sleep(200);Console.WriteLine("child early");System.Threading.Thread.Sleep(2000);Console.WriteLine("child late");Console.Error.WriteLine("child stderr");return 0;}
  if(a[0]=="spawn") {
   var s=new SI();s.cb=Marshal.SizeOf(typeof(SI));s.flags=0x100;s.input=GetStdHandle(-10);s.output=GetStdHandle(-11);s.error=GetStdHandle(-12);PI p;
   string exe=Process.GetCurrentProcess().MainModule.FileName;
   if(!CreateProcess(exe,new StringBuilder("\""+exe+"\" child \""+a[1]+"\""),IntPtr.Zero,IntPtr.Zero,true,0x08000000,IntPtr.Zero,Environment.CurrentDirectory,ref s,out p))return 99;
   File.WriteAllText(a[1]+".spawned-pid",p.pid.ToString());CloseHandle(p.thread);CloseHandle(p.process);
   Console.WriteLine("parent finished");return 37;
  }
  if(a[0]=="timeout") {System.Threading.Thread.Sleep(1200);Console.WriteLine("natural timeout exit");return 0;}
  if(a[0]=="bulk") {for(int i=0;i<30000;i++){Console.WriteLine("stdout "+i+" \u00f1\u6f22\u5b57\ud83d\ude80");Console.Error.WriteLine("stderr "+i+" \u00e1\u03a9");}return 0;}
  if(a[0]=="invalid") {Console.OpenStandardOutput().WriteByte(0xff);Console.Error.WriteLine("valid stderr \u6f22\u03a9");return 2;}
  Console.WriteLine("Unicode \u00f1\u6f22\u5b57\ud83d\ude80");Console.Error.WriteLine("stderr \u00e1\u03a9");return int.Parse(a[0]);
 }
}
