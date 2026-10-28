<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TemporaryExportFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TemporaryExportFileTest extends TestCase
{
    public function test_abandoned_response_releases_its_private_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'horus-s03-');
        file_put_contents($path, 'synthetic export');
        $response = new BinaryFileResponse(new TemporaryExportFile($path));
        $this->assertFileExists($path);
        unset($response);
        $this->assertFileDoesNotExist($path);
    }

    public function test_completed_download_removes_its_private_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'horus-s03-');
        file_put_contents($path, 'synthetic export');
        $response = (new BinaryFileResponse(new TemporaryExportFile($path)))->deleteFileAfterSend(true);
        ob_start();
        try {
            $response->sendContent();
            $this->assertSame('synthetic export', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->assertFileDoesNotExist($path);
    }
}
