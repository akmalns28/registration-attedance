@extends('layouts.error')

@section('title', '413 - Unggahan Terlalu Besar')

@section('content')
    <div class="empty">
        <div class="empty-header">413</div>
        <p class="empty-title">Unggahan terlalu besar</p>
        <p class="empty-subtitle text-secondary">Kurangi ukuran atau jumlah berkas, lalu coba unggah kembali. Untuk event, batas total satu permintaan adalah 64 MB.</p>
        <div class="empty-action">
            <a href="{{ route('events.index') }}" class="btn btn-primary">Daftar Event</a>
        </div>
    </div>
@endsection
