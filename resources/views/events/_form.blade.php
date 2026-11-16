@php
    $isEdit = isset($event);

    $selectedStatus = old('status', $event->status ?? 'draft');

    $registrationStart = old(
        'registration_start_at',
        isset($event) && $event->registration_start_at ? $event->registration_start_at->format('Y-m-d\TH:i') : '',
    );

    $registrationEnd = old(
        'registration_end_at',
        isset($event) && $event->registration_end_at ? $event->registration_end_at->format('Y-m-d\TH:i') : '',
    );
@endphp

<div class="row row-cards">

    {{-- Main Information --}}
    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Informasi Event
                </h3>
            </div>

            <div class="card-body">

                {{-- Title --}}
                <div class="mb-3">
                    <label for="title" class="form-label required">
                        Nama Event
                    </label>

                    <input type="text" id="title" name="title"
                        class="form-control @error('title') is-invalid @enderror"
                        value="{{ old('title', $event->title ?? '') }}" placeholder="Contoh: Annual Gathering 2027"
                        maxlength="200" required>

                    @error('title')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="mb-3">
                    <label for="description" class="form-label">
                        Deskripsi Event
                    </label>

                    <textarea id="description" name="description" rows="4"
                        class="form-control @error('description') is-invalid @enderror" placeholder="Masukkan deskripsi event">{{ old('description', $event->description ?? '') }}</textarea>

                    @error('description')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- Location --}}
                <div class="mb-3">
                    <label for="location" class="form-label">
                        Lokasi Event
                    </label>

                    <input type="text" id="location" name="location"
                        class="form-control @error('location') is-invalid @enderror"
                        value="{{ old('location', $event->location ?? '') }}"
                        placeholder="Contoh: Bandung Convention Center" maxlength="255">

                    @error('location')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

            </div>
        </div>

        {{-- Event Dates --}}
        <div class="card mt-3">

            <div class="card-header">
                <h3 class="card-title">
                    Periode Pelaksanaan
                </h3>
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="start_date" class="form-label required">
                            Tanggal Mulai
                        </label>

                        <input type="date" id="start_date" name="start_date"
                            class="form-control @error('start_date') is-invalid @enderror"
                            value="{{ old('start_date', isset($event) ? $event->start_date->format('Y-m-d') : '') }}"
                            required>

                        @error('start_date')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="end_date" class="form-label required">
                            Tanggal Selesai
                        </label>

                        <input type="date" id="end_date" name="end_date"
                            class="form-control @error('end_date') is-invalid @enderror"
                            value="{{ old('end_date', isset($event) ? $event->end_date->format('Y-m-d') : '') }}"
                            required>

                        @error('end_date')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                </div>

                <div class="form-hint mt-3">
                    Event dapat berlangsung satu hari atau beberapa hari.
                    Rundown kegiatan akan dikelola setelah event dibuat.
                </div>

            </div>
        </div>

        {{-- Registration Period --}}
        <div class="card mt-3">

            <div class="card-header">
                <h3 class="card-title">
                    Periode Registrasi
                </h3>
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="registration_start_at" class="form-label">
                            Registrasi Dibuka
                        </label>

                        <input type="datetime-local" id="registration_start_at" name="registration_start_at"
                            class="form-control @error('registration_start_at') is-invalid @enderror"
                            value="{{ $registrationStart }}">

                        @error('registration_start_at')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="registration_end_at" class="form-label">
                            Registrasi Ditutup
                        </label>

                        <input type="datetime-local" id="registration_end_at" name="registration_end_at"
                            class="form-control @error('registration_end_at') is-invalid @enderror"
                            value="{{ $registrationEnd }}">

                        @error('registration_end_at')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                </div>

                <div class="form-hint mt-3">
                    Kosongkan keduanya jika periode registrasi
                    belum ditentukan.
                </div>

            </div>
        </div>

    </div>

    {{-- Settings --}}
    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Pengaturan Event
                </h3>
            </div>

            <div class="card-body">

                {{-- Capacity --}}
                <div class="mb-3">
                    <label for="capacity" class="form-label">
                        Kapasitas Peserta
                    </label>

                    <input type="number" id="capacity" name="capacity"
                        class="form-control @error('capacity') is-invalid @enderror"
                        value="{{ old('capacity', $event->capacity ?? '') }}" min="1" step="1"
                        placeholder="Unlimited">

                    @error('capacity')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Kosongkan untuk kapasitas tidak terbatas.
                    </div>
                </div>

                {{-- Status --}}
                <div class="mb-3">
                    <label for="status" class="form-label required">
                        Status Event
                    </label>

                    <select id="status" name="status" class="form-select @error('status') is-invalid @enderror"
                        required>
                        <option value="draft" @selected($selectedStatus === 'draft')>
                            Draft
                        </option>

                        <option value="published" @selected($selectedStatus === 'published')>
                            Published
                        </option>

                        <option value="closed" @selected($selectedStatus === 'closed')>
                            Closed
                        </option>

                        <option value="cancelled" @selected($selectedStatus === 'cancelled')>
                            Cancelled
                        </option>
                    </select>

                    @error('status')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                @if ($isEdit)
                    @php
                        $registeredCount = $event->registered_participants_count ?? 0;

                        $currentRemaining =
                            $event->capacity === null ? null : max(0, $event->capacity - $registeredCount);
                    @endphp

                    <div class="alert alert-info mt-3 mb-0">

                        <div class="fw-semibold mb-2">
                            Informasi Kapasitas
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Peserta Terdaftar</span>

                            <strong>
                                {{ number_format($registeredCount, 0, ',', '.') }}
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Kapasitas Saat Ini</span>

                            <strong>
                                {{ $event->capacity === null ? 'Unlimited' : number_format($event->capacity, 0, ',', '.') }}
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between">
                            <span>Sisa Kapasitas</span>

                            <strong>
                                {{ $currentRemaining === null ? 'Unlimited' : number_format($currentRemaining, 0, ',', '.') }}
                            </strong>
                        </div>

                        <hr>

                        <div class="small">
                            Kapasitas baru tidak boleh kurang dari
                            <strong>{{ $registeredCount }}</strong> peserta.
                        </div>

                    </div>
                @endif

            </div>
        </div>

    </div>

    {{-- Actions --}}
    <div class="col-12">

        <div class="d-flex justify-content-end gap-2">

            <a href="{{ $isEdit ? route('events.show', $event) : route('events.index') }}"
                class="btn btn-outline-secondary">
                Batal
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="ti ti-device-floppy me-2"></i>

                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Event' }}
            </button>

        </div>

    </div>

</div>
