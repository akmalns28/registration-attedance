<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_404_page_is_rendered(): void
    {
        $this->get('/route-yang-tidak-tersedia')->assertNotFound()->assertSee('Halaman Tidak Ditemukan');
    }

    public function test_405_page_is_rendered(): void
    {
        $this->get('/logout')->assertStatus(405)->assertSee('Metode Permintaan Tidak Diizinkan');
    }

    public function test_403_page_is_rendered(): void
    {
        \Illuminate\Support\Facades\Route::get('/test-forbidden', fn() => abort(403));

        $this->get('/test-forbidden')->assertForbidden()->assertSee('Akses Ditolak');
    }
}
