@php
    $isEdit = isset($day);

    $selectedDate = old('date', $isEdit ? $day->date->format('Y-m-d') : '');

    $isActive = old('is_active', $day->is_active ?? true);
@endphp

<div class="row row-cards">

    <div class="col-lg-8">
        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Informasi Hari Pelaksanaan
                </h3>
            </div>

            <div class="card-body">

                <div class="mb-3">
                    <label for="date" class="form-label required">
                        Tanggal Pelaksanaan
                    </label>

                    <input type="date" id="date" name="date"
                        class="form-control @error('date') is-invalid @enderror" value="{{ $selectedDate }}"
                        min="{{ $event->start_date->format('Y-m-d') }}" max="{{ $event->end_date->format('Y-m-d') }}"
                        required>

                    @error('date')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Pilih tanggal antara
                        {{ $event->start_date->format('d/m/Y') }}
                        sampai
                        {{ $event->end_date->format('d/m/Y') }}.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="title" class="form-label">
                        Nama Hari / Kegiatan
                    </label>

                    <input type="text" id="title" name="title"
                        class="form-control @error('title') is-invalid @enderror"
                        value="{{ old('title', $day->title ?? '') }}" placeholder="Contoh: Opening Day" maxlength="150">

                    @error('title')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">
                        Deskripsi
                    </label>

                    <textarea id="description" name="description" rows="4" maxlength="5000"
                        class="form-control @error('description') is-invalid @enderror" placeholder="Deskripsi hari pelaksanaan (opsional)">{{ old('description', $day->description ?? '') }}</textarea>

                    @error('description')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Status
                </h3>
            </div>

            <div class="card-body">

                <input type="hidden" name="is_active" value="0">

                <label class="form-check form-switch">
                    <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                        @checked((bool) $isActive)>

                    <span class="form-check-label">
                        Hari Pelaksanaan Aktif
                    </span>
                </label>

                <div class="text-secondary small mt-3">
                    Hari pelaksanaan yang nonaktif tetap
                    tersimpan dan tidak dihapus dari event.
                </div>

            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="d-flex justify-content-end gap-2">

            <a href="{{ route('events.show', $event) }}" class="btn btn-outline-secondary">
                Batal
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="ti ti-device-floppy me-2"></i>
                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Event Day' }}
            </button>

        </div>
    </div>

</div>
