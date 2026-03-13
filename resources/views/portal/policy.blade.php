@extends('layouts.app')

@section('content')
    <main class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-5 py-8 lg:px-10">
        <section class="rounded-xl border border-[#dddddd] bg-white p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)] lg:p-8">
            <div class="space-y-4">
                <h1 class="text-[#33373c]">{{ $title }}</h1>
                <p class="text-[#4d5257]">{{ $intro }}</p>

                <div class="space-y-4 text-[#4b5055]">
                    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris id ligula sed neque pretium cursus. Integer ac mauris vitae arcu laoreet sodales. Donec vitae nisi velit. Nulla facilisi.</p>
                    <p>Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae; Cras ullamcorper felis nec sapien convallis, a mattis nibh commodo. Sed in gravida orci. Nulla facilisi.</p>
                    <p>Curabitur posuere, leo nec finibus suscipit, purus lacus posuere eros, vitae luctus lorem mauris sed est. Pellentesque eleifend purus id elit placerat, non egestas turpis ultricies.</p>
                    <p>Nam et sem non massa commodo malesuada. Duis tristique sollicitudin erat, nec sodales neque facilisis non. Sed malesuada convallis dui, sed interdum turpis tincidunt sit amet.</p>
                </div>

                <div class="border-t border-[#d7d7d7] pt-5">
                    <a class="inline-flex items-center gap-2 text-sm font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.register') }}">
                        <span aria-hidden="true">←</span>
                        Back to register
                    </a>
                </div>
            </div>
        </section>
    </main>
@endsection
