<div data-attendance-scanner data-validate-url="{{ $validationUrl }}" data-checkin-url="{{ $checkinUrl }}" data-timezone="{{ config('app.timezone') }}" data-fixed-event-id="{{ $fixedEvent?->id }}" data-fixed-day-id="{{ $fixedDay?->id }}" data-auto-resume-delay="1200" data-duplicate-suppression-ms="8000">
    <div class="alert alert-info" role="note">
        <i class="ti ti-info-circle me-2" aria-hidden="true"></i>
        Kehadiran belum dicatat. Periksa hasil validasi, lalu tekan Konfirmasi Check-in untuk mencatat kehadiran.
        Check-in hanya tersedia pada tanggal hari pelaksanaan sesuai zona waktu {{ config('app.timezone') }}.
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        @if (! $fixedDay)
                            <div class="col-12 col-sm-6">
                                <label for="scanner-event" class="form-label required">Event</label>
                                <select id="scanner-event" class="form-select" required @disabled($fixedEvent !== null)>
                                    @if (! $fixedEvent)
                                        <option value="">Pilih Event Published</option>
                                    @endif
                                    @foreach ($events as $scannerEvent)
                                        <option value="{{ $scannerEvent['id'] }}" @selected($fixedEvent?->id === $scannerEvent['id'])>{{ $scannerEvent['title'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="scanner-day" class="form-label required">Hari Pelaksanaan</label>
                                <select id="scanner-day" class="form-select" required disabled>
                                    <option value="">{{ $fixedEvent ? 'Pilih hari pelaksanaan' : 'Pilih event terlebih dahulu' }}</option>
                                </select>
                            </div>
                        @endif
                        <div class="col-12">
                            <label for="scanner-camera" class="form-label">Kamera</label>
                            <select id="scanner-camera" class="form-select" disabled aria-describedby="scanner-camera-help">
                                <option value="">Otomatis (utamakan kamera belakang)</option>
                            </select>
                            <div id="scanner-camera-help" class="form-hint">
                                Daftar kamera tersedia setelah Anda menekan Mulai Kamera dan memberikan izin.
                            </div>
                        </div>
                    </div>

                    @if (empty($events) || ($fixedEvent && empty($events[0]['days'])))
                        <div class="alert alert-warning">{{ $fixedEvent ? 'Event ini belum memiliki hari pelaksanaan aktif.' : 'Belum ada Event Published dengan hari pelaksanaan aktif.' }}</div>
                    @endif

                    <div class="attendance-camera-stage mb-3">
                        <div id="qr-reader" aria-label="Pratinjau kamera QR"></div>
                        <div data-scanner-placeholder class="attendance-camera-placeholder text-center text-white p-3">
                            <i class="ti ti-camera fs-1 d-block mb-2" aria-hidden="true"></i>
                            {{ $fixedDay ? 'Tekan Mulai Kamera untuk memindai QR peserta.' : 'Pilih event dan hari pelaksanaan, lalu mulai kamera.' }}
                        </div>
                    </div>

                    <div data-scanner-feedback class="alert alert-secondary mb-3" role="status" aria-live="polite" aria-atomic="true">
                        {{ $fixedDay ? 'Kamera siap dimulai untuk hari pelaksanaan ini.' : 'Kamera siap dimulai setelah event dan hari pelaksanaan dipilih.' }}
                    </div>

                    <div class="row g-2 attendance-scanner-controls">
                        <div class="col-12 col-sm-4">
                            <button type="button" data-scanner-start class="btn btn-primary w-100" disabled>
                                <i class="ti ti-camera me-2" aria-hidden="true"></i>Mulai Kamera
                            </button>
                        </div>
                        <div class="col-6 col-sm-4">
                            <button type="button" data-scanner-stop class="btn btn-outline-secondary w-100" disabled>
                                <i class="ti ti-player-stop me-2" aria-hidden="true"></i>Hentikan
                            </button>
                        </div>
                        <div class="col-6 col-sm-4">
                            <button type="button" data-scanner-restart class="btn btn-outline-primary w-100" disabled>
                                <i class="ti ti-refresh me-2" aria-hidden="true"></i>Mulai Ulang
                            </button>
                        </div>
                    </div>
                    <p class="text-secondary small mb-0 mt-3">
                        Arahkan QR undangan ke tengah kamera. Gunakan HTTPS atau localhost untuk mengakses kamera.
                    </p>
                    <noscript><div class="alert alert-warning mt-3">Aktifkan JavaScript untuk menggunakan QR Scanner.</div></noscript>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <section data-scanner-result class="card d-none" aria-live="polite" aria-atomic="true" aria-label="Hasil validasi registrasi">
                <div data-scanner-result-color class="card-status-top"></div>
                <div class="card-body">
                    <h3 data-scanner-result-title class="card-title" tabindex="-1"></h3>
                    <p data-scanner-result-message class="mb-3"></p>
                    <dl data-scanner-participant class="attendance-scanner-participant mb-3 d-none">
                        <dt>Nama Peserta</dt><dd data-result-field="participant_name"></dd>
                        <dt>Nomor Registrasi</dt><dd data-result-field="registration_number"></dd>
                        <dt>Jenis Peserta</dt><dd data-result-field="participant_type"></dd>
                        <dt>Event</dt><dd data-result-field="event_name"></dd>
                        <dt>Hari Pelaksanaan</dt><dd data-result-field="event_day"></dd>
                        <dt>Status Registrasi</dt><dd data-result-field="registration_status"></dd>
                    </dl>
                    <dl data-scanner-checkin-details class="attendance-scanner-participant mb-3 d-none">
                        <dt>Status Kehadiran</dt><dd>Hadir</dd>
                        <dt>Waktu Check-in</dt><dd data-checkin-time></dd>
                        <dt>Petugas</dt><dd data-checkin-operator></dd>
                    </dl>
                    <button type="button" data-scanner-confirm class="btn btn-success btn-lg w-100 mb-2 d-none" disabled>
                        Konfirmasi Check-in
                    </button>
                    <button type="button" data-scanner-again class="btn btn-primary btn-lg w-100" disabled>
                        <i class="ti ti-scan me-2" aria-hidden="true"></i>Pindai Lagi
                    </button>
                </div>
            </section>
        </div>
    </div>
    <script type="application/json" data-scanner-events>{!! Illuminate\Support\Js::encode($events) !!}</script>
</div>
