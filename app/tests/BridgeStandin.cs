using System;
using System.Diagnostics;
using System.IO;
using System.Threading;

public static class BridgeStandin
{
    public static void Main(string[] args)
    {
        int pid = Process.GetCurrentProcess().Id;
        if (args.Length > 0 && args[0] == "--peer")
        {
            File.WriteAllText("peer.pid", pid.ToString());
            Thread.Sleep(120000);
            return;
        }
        File.AppendAllText("starts.txt", pid + "\n");
        File.WriteAllText("arguments.txt", String.Join("\n", args));
        File.WriteAllText("steam.txt", Environment.GetEnvironmentVariable("SteamAppId") + "\n" + Environment.GetEnvironmentVariable("SteamGameId"));
        Console.CancelKeyPress += (sender, signal) => {
            signal.Cancel = true;
            File.AppendAllText("signal.txt", pid + "\n");
            if (File.Exists("ignore-stop")) return;
            Thread.Sleep(400);
            File.WriteAllText("saved.txt", pid.ToString());
            Environment.Exit(0);
        };
        File.WriteAllText("ready.pid", pid.ToString());
        if (File.Exists("share-console"))
            Process.Start(new ProcessStartInfo(File.ReadAllText("share-console"), "--peer")
                { UseShellExecute = false });
        // Own disposable processes expire even if their test runner is interrupted.
        for (int i = 0; i < 1200; ++i)
        {
            if (File.Exists("release-stop")) { File.WriteAllText("saved.txt", pid.ToString()); return; }
            Thread.Sleep(100);
        }
    }
}
