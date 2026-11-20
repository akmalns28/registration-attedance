<div class="modal fade" id="eventDayInvitationModal" tabindex="-1" aria-labelledby="eventDayInvitationTitle"
    aria-describedby="eventDayInvitationHelp" aria-hidden="true" data-event-day-invitation-modal>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="POST">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title" id="eventDayInvitationTitle">Kelola Hari Undangan</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3" data-invitation-details hidden>
                    <div class="fw-semibold text-break" data-invitation-participant></div>
                    <div class="text-secondary text-break" data-invitation-event></div>
                </div>
                <p class="text-secondary small" id="eventDayInvitationHelp">Hari dengan kehadiran tercatat tetap dipilih.</p>
                <div class="text-secondary" role="status" data-invitation-loading hidden>
                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Memuat hari undangan…
                </div>
                <div class="alert alert-danger" role="alert" tabindex="-1" data-invitation-error hidden></div>
                <fieldset data-invitation-options hidden>
                    <legend class="form-label">Hari pelaksanaan</legend>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-invitation-select-all>Pilih Semua</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-invitation-clear-all>Kosongkan Pilihan</button>
                    </div>
                    <div data-invitation-days></div>
                </fieldset>
                <template data-invitation-day-template>
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="day_ids[]">
                        <span class="form-check-label text-break">
                            <span data-invitation-day-label></span>
                            <span class="d-block small text-secondary" data-invitation-day-note></span>
                        </span>
                    </label>
                </template>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary" disabled>
                    <span data-invitation-save>Simpan</span>
                    <span data-invitation-saving hidden><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Menyimpan…</span>
                </button>
            </div>
        </form>
    </div>
</div>
