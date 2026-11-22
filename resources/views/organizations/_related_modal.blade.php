<x-ui.modal id="organization-related-modal" title="Detail Organisasi" size="sm">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <strong class="text-break" data-organization-modal-name></strong>
        <span class="badge" data-organization-modal-status></span>
    </div>
    <p class="text-secondary small mb-2" data-organization-modal-employees></p>
    <div class="overflow-y-auto" style="max-height: 55vh">
        @foreach (['departments', 'divisions'] as $type)
            <section class="mb-2" data-organization-modal-section="{{ $type }}">
                <h3 class="h4 mb-1"></h3>
                <ul class="list-group list-group-flush"></ul>
            </section>
        @endforeach
    </div>
</x-ui.modal>
