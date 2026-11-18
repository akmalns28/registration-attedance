@can('events.update')
    <div class="modal modal-blur fade" id="cancelRegistrationModal" tabindex="-1"
        aria-labelledby="cancelRegistrationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">

            <div class="modal-content">

                <div class="modal-status bg-danger"></div>

                <div class="modal-body text-center py-4">

                    <x-ui.icon name="user-x" class="text-danger fs-1" />

                    <h3 class="mt-3" id="cancelRegistrationModalLabel">
                        Batalkan Registrasi?
                    </h3>

                    <p class="text-secondary">

                        Apakah Anda yakin ingin membatalkan registrasi

                        <strong id="cancelRegistrationName"></strong>?

                    </p>

                    <div class="alert alert-warning text-start mb-0">

                        <x-ui.icon name="alert-triangle" class="me-1" />

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
