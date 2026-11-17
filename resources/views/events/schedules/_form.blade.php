@php
    $isEdit = isset($schedule);

    $startTime = old('start_time', $isEdit ? substr($schedule->start_time, 0, 5) : '');

    $endTime = old('end_time', $isEdit ? substr($schedule->end_time, 0, 5) : '');
@endphp

<div class="row row-cards">

    {{-- Main Information --}}
    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Informasi Rundown
                </h3>
            </div>

            <div class="card-body">

                {{-- Title --}}
                <div class="mb-3">

                    <label for="title" class="form-label required">
                        Nama Kegiatan
                    </label>

                    <input type="text" id="title" name="title"
                        class="form-control @error('title') is-invalid @enderror"
                        value="{{ old('title', $schedule->title ?? '') }}" placeholder="Contoh: Opening Ceremony"
                        maxlength="200" required>

                    @error('title')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                </div>

                {{-- Time --}}
                <div class="row g-3 mb-3">

                    <div class="col-md-6">

                        <label for="start_time" class="form-label required">
                            Jam Mulai
                        </label>

                        <input type="time" id="start_time" name="start_time"
                            class="form-control @error('start_time') is-invalid @enderror" value="{{ $startTime }}"
                            step="60" required>

                        @error('start_time')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror

                    </div>

                    <div class="col-md-6">

                        <label for="end_time" class="form-label required">
                            Jam Selesai
                        </label>

                        <input type="time" id="end_time" name="end_time"
                            class="form-control @error('end_time') is-invalid @enderror" value="{{ $endTime }}"
                            step="60" required>

                        @error('end_time')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror

                    </div>

                </div>

                {{-- PIC --}}
                <div class="mb-3">

                    <label for="pic" class="form-label">
                        PIC Kegiatan
                    </label>

                    <input type="text" id="pic" name="pic"
                        class="form-control @error('pic') is-invalid @enderror"
                        value="{{ old('pic', $schedule->pic ?? '') }}" placeholder="Nama penanggung jawab kegiatan"
                        maxlength="255">

                    @error('pic')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                </div>

                {{-- Description --}}
                <div class="mb-3">

                    <label for="description" class="form-label">
                        Deskripsi
                    </label>

                    <textarea id="description" name="description" rows="4" maxlength="5000"
                        class="form-control @error('description') is-invalid @enderror" placeholder="Deskripsi kegiatan (opsional)">{{ old('description', $schedule->description ?? '') }}</textarea>

                    @error('description')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                </div>

            </div>
        </div>
    </div>

    {{-- Settings --}}
    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Pengaturan Rundown
                </h3>
            </div>

            <div class="card-body">

                {{-- Sort Order --}}
                <div class="mb-3">

                    <label for="sort_order" class="form-label required">
                        Urutan Kegiatan
                    </label>

                    <input type="number" id="sort_order" name="sort_order"
                        class="form-control @error('sort_order') is-invalid @enderror"
                        value="{{ old('sort_order', $schedule->sort_order ?? 0) }}" min="0" max="65535"
                        step="1" required>

                    @error('sort_order')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Urutan digunakan sebagai prioritas kedua
                        jika beberapa kegiatan memiliki jam mulai yang sama.
                    </div>

                </div>

            </div>
        </div>

    </div>

    {{-- Actions --}}
    <div class="col-12">

        <div class="d-flex justify-content-end gap-2">

            <a href="{{ route('events.days.show', [$event, $day]) }}#day-rundown" class="btn btn-outline-secondary">
                Batal
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="ti ti-device-floppy me-2"></i>

                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Rundown' }}
            </button>

        </div>

    </div>

</div>
