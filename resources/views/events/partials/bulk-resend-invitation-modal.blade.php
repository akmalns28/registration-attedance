<div class="modal modal-blur fade" id="bulkResendInvitationModal" tabindex="-1"
    aria-labelledby="bulkResendInvitationTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('events.participants.invitations.resend', $event) }}">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title" id="bulkResendInvitationTitle">Kirim ulang undangan?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-3">
                    <dt class="col-9">Peserta dipilih</dt>
                    <dd class="col-3 text-end" data-resend-total></dd>
                    <dt class="col-9">Dengan email valid</dt>
                    <dd class="col-3 text-end" data-resend-with-email></dd>
                    <dt class="col-9">Tanpa email valid</dt>
                    <dd class="col-3 mb-0 text-end" data-resend-without-email></dd>
                </dl>
                <p class="mb-0 text-secondary">
                    Undangan menggunakan informasi event terbaru. Peserta tanpa email valid akan dilewati.
                </p>
                <div class="alert alert-danger mt-3 mb-0" data-resend-error role="alert" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary" data-resend-submit disabled>Kirim Undangan</button>
            </div>
        </form>
    </div>
</div>
