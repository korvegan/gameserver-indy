<?php
declare(strict_types=1);
namespace App\Tests;

use App\Instance\NativePaths;
use App\Runtime\NativePowerShell;
use App\Storage\PrivateFiles;
use PHPUnit\Framework\Attributes\DataProvider;

final class NativeManagedMarkerTest extends SliceTestCase
{
    public static function markers(): iterable
    {
        $valid = '{"format":"gameserver-indy-state","version":1,"access_layout":"shared-data"}';
        yield 'legacy isolated fixture' => [null, true, true];
        yield 'installed production layout without prototype marker' => [$valid, false, true];
        yield 'installed layout alongside prototype' => [$valid, true, true];
        yield 'missing both markers' => [null, false, false];
        yield 'unknown production version cannot fall back' => [str_replace('"version":1', '"version":2', $valid), true, false];
        yield 'wrong production layout' => [str_replace('shared-data', 'other', $valid), false, false];
        yield 'noninteger version' => [str_replace('"version":1', '"version":"1"', $valid), false, false];
        yield 'malformed production JSON cannot fall back' => ['{', true, false];
    }

    #[DataProvider('markers')]
    public function testNativeStatusAcceptsOnlySupportedStateMarkers(?string $managed, bool $prototype, bool $accepted): void
    {
        if (getenv('SLICE_WINDOWS_TEST') !== '1') { self::markTestSkipped('Native eigene Statusanfragen ausdrücklich aktivieren.'); }
        if (!$prototype) { unlink($this->directory.'/.indy-symfony-slice'); }
        if ($managed !== null) { PrivateFiles::write($this->directory.'/.indy-managed-state', $managed); }
        $folder = $this->directory.'/native/requests'; PrivateFiles::directory($folder);
        $nonce = bin2hex(random_bytes(16)); $request = $folder.'/request-'.$nonce.'.json';
        $reply = $folder.'/reply-'.$nonce.'.json'; $shell = new NativePowerShell(new NativePaths());
        // Read only; unique nonexistent fixture executable, never a registered game installation.
        PrivateFiles::write($request, PrivateFiles::json((object) ['Action'=>'Status',
            'ServerDirectory'=>$shell->windowsPath($folder), 'ExecutableName'=>'indy-marker-fixture.exe']));
        PrivateFiles::write($reply, '{}');
        $process = $shell->run(dirname(__DIR__).'/tools/invoke-managed-server.ps1', ['-RequestPath',$shell->windowsPath($request)], 20);
        self::assertSame($accepted, $process->isSuccessful(), 'Native marker validation');
        $response = PrivateFiles::object(PrivateFiles::read($reply));
        if ($accepted) { self::assertTrue($response->ok); self::assertSame([], $response->result->processes); }
        else { self::assertFalse($response->ok ?? false); }
    }
}
