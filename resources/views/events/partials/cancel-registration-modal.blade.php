@can('events.update')
    <div class="modal modal-blur fade" id="cancelRegistrationModal" tabindex="-1"
        aria-labelledby="cancelRegistrationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">

            <div class="modal-content">

                <div class="modal-status bg-danger"></div>

                <div class="modal-body text-center py-4">

                    <i class="ti ti-user-x text-danger fs-1"></i>

                    <h3 class="mt-3" id="cancelRegistrationModalLabel">
                        Batalkan Registrasi?
                    </h3>

                    <p class="text-secondary">

                        Apakah Anda yakin ingin membatalkan registrasi

                        <strong id="cancelRegistrationName"></strong>?

                    </p>

                    <div class="alert alert-warning text-start mb-0">

                        <i class="ti ti-alert-triangle me-1"></i>

                        Peserta yang dibatalkan tidak dihitung
                        dalam kapasitas event.

                        Data registrasi tetap tersimpan.

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Batal
                    </button>

                    <form id="cancelRegistrationForm" method="POST" data-form
                        data-loading-text="Membatalkan registrasi...">

                        @csrf
                        @method('DELETE')

                        <button type="submit" class="btn btn-danger" disabled>
                            Ya, Batalkan
                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>
@endcan
