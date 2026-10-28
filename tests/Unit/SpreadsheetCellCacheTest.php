<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SpreadsheetCellCache;
use DateInterval;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SpreadsheetCellCacheTest extends TestCase
{
    public function test_values_are_serialized_and_the_temporary_file_is_removed_on_close(): void
    {
        $cache = new SpreadsheetCellCache;
        $resource = (new \ReflectionProperty($cache, 'stream'))->getValue($cache);
        $path = stream_get_meta_data($resource)['uri'];
        $value = (object) ['text' => '=1+1'];
        $cache->set('cell', $value);
        $value->text = 'changed';

        $this->assertSame('=1+1', $cache->get('cell')->text);
        $cache->set('cell', 42);
        $this->assertSame(42, $cache->get('cell'));
        $this->assertTrue($cache->clear());
        $this->assertSame('missing', $cache->get('cell', 'missing'));
        $cache->close();
        $cache->close();
        $this->assertFileDoesNotExist($path);

        $this->expectException(RuntimeException::class);
        $cache->set('closed', 'value');
    }

    public function test_inherited_bulk_operations_use_the_disk_cache_and_respect_expiry(): void
    {
        $cache = new SpreadsheetCellCache;
        try {
            $cache->setMultiple(['a' => null, 'b' => 'literal', 'c' => 0]);
            $this->assertTrue($cache->has('a'));
            $this->assertSame(['a' => null, 'b' => 'literal', 'c' => 0], $cache->getMultiple(['a', 'b', 'c']));
            $cache->deleteMultiple(['a', 'b']);
            $this->assertSame(['a' => 'missing', 'b' => 'missing'], $cache->getMultiple(['a', 'b'], 'missing'));
            $cache->set('c', 'expired', 0);
            $this->assertFalse($cache->has('c'));
            $cache->set('future', 'value', new DateInterval('PT10S'));
            $this->assertSame('value', $cache->get('future'));
        } finally {
            $cache->close();
        }
    }

    public function test_short_reads_fail_instead_of_returning_an_incomplete_cell(): void
    {
        $cache = new SpreadsheetCellCache;
        try {
            $cache->set('cell', str_repeat('x', 1048576));
            $resource = (new \ReflectionProperty($cache, 'stream'))->getValue($cache);
            ftruncate($resource, 0);
            $this->expectExceptionMessage('La caché temporal del Excel está incompleta.');
            $cache->get('cell');
        } finally {
            $cache->close();
        }
    }

    public function test_buffer_boundaries_and_rewrites_preserve_random_access(): void
    {
        $cache = new SpreadsheetCellCache;
        try {
            for ($i = 0; $i < 200; $i++) {
                $cache->set('cell'.$i, str_repeat((string) ($i % 10), 16384));
            }
            foreach ([0, 62, 65, 127, 190, 199, 100, 0] as $i) {
                $this->assertSame(str_repeat((string) ($i % 10), 16384), $cache->get('cell'.$i));
            }
            $cache->set('cell0', 'updated');
            $this->assertSame('updated', $cache->get('cell0'));
            $cache->clear();
            $cache->set('new', 'after truncation');
            $this->assertSame('after truncation', $cache->get('new'));
        } finally {
            $cache->close();
        }
    }
}
