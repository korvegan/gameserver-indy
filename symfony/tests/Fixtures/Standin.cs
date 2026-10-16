using System;
using System.IO;
using System.Threading;

public static class IndySymfonyStandin {
    public static void Main() {
        int pid = System.Diagnostics.Process.GetCurrentProcess().Id;
        File.WriteAllText("process.pid", pid.ToString());
        Console.CancelKeyPress += (sender, args) => {
            args.Cancel = true;
            File.WriteAllText("graceful-stop.txt", pid.ToString());
            Environment.Exit(0);
        };
        Thread.Sleep(300);
        File.WriteAllText("ready.pid", pid.ToString());
        Thread.Sleep(120000); // A broken QA run never leaves a permanent server.
    }
}
