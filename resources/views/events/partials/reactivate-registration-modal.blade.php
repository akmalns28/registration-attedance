@can('events.update')
    <div class="modal modal-blur fade" id="reactivateRegistrationModal" tabindex="-1"
        aria-labelledby="reactivateRegistrationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">

            <div class="modal-content">

                <div class="modal-status bg-success"></div>

                <div class="modal-body text-center py-4">

                    <x-ui.icon name="user-check" class="text-success fs-1" />

                    <h3 class="mt-3" id="reactivateRegistrationModalLabel">
                        Aktifkan Kembali Registrasi?
                    </h3>

                    <p class="text-secondary">

                        Apakah Anda yakin ingin mengaktifkan kembali

                        <strong id="reactivateRegistrationName"></strong>?

                    </p>

                    <div class="alert alert-info text-start mb-0">

                        <x-ui.icon name="info-circle" class="me-1" />

                        Peserta akan kembali berstatus
                        <strong>Registered</strong>.

                        Reaktivasi tetap memperhitungkan
                        kapasitas event yang tersedia.

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Batal
                    </button>

                    <form id="reactivateRegistrationForm" method="POST" data-form
                        data-loading-text="Mengaktifkan registrasi...">
                        @csrf

                        <button type="submit" class="btn btn-success" disabled>
                            <x-ui.icon name="user-check" class="me-1" />
                            Ya, Aktifkan
                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>
@endcan
