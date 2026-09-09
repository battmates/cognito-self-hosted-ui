<section class="portal-card rounded-xl border p-5">
    <table data-users-table class="w-full text-left portal-copy">
        <thead><tr class="border-b portal-divider">
            @foreach(['User name', 'Email address', 'Email verified', 'Confirmation status', 'Status'] as $heading)
                <th class="pb-3 pr-5 last:pr-0 whitespace-nowrap" scope="col">{{ $heading }}</th>
            @endforeach
            <th class="pb-3" scope="col"><span class="sr-only">Actions</span></th>
        </tr></thead>
        <tbody data-user-rows>
            @include('portal.partials.admin-user-rows')
            @if(empty($users))<tr data-empty-row><td colspan="6" class="py-6">{{ $cursor ? 'No matches in this batch. Load more to keep searching.' : 'No matching accounts.' }}</td></tr>@endif
        </tbody>
    </table>
    @if($cursor)<button type="button" data-load-more data-cursor="{{ $cursor }}" class="mt-5 rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white">Load more</button>@endif
</section>
