@inject('impersonation', \App\Support\Access\Impersonation::class)

{{-- Shown on every signed-in page while an Owner is viewing Backoffice as someone else. It stays on screen when the page scrolls. --}}
@if ($impersonation->isActive())
    <div role="status" data-test="impersonation-banner" class="sticky top-0 z-50 border-b-4 border-ink bg-red-600 text-white shadow-lg print:hidden">
        <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 px-4 py-2.5 text-center">
            <span class="font-nav text-base font-bold tracking-wide sm:text-lg">
                {{ __('Viewing Backoffice as :name', ['name' => $impersonation->impersonated()->name]) }}
            </span>

            <form method="POST" action="{{ route('impersonation.stop') }}">
                @csrf
                <button
                    type="submit"
                    data-test="stop-impersonating"
                    class="cursor-pointer rounded-md border-2 border-ink bg-banana px-4 py-1.5 font-nav text-sm font-bold text-ink hover:bg-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none sm:text-base"
                >
                    {{ __('Return to :name', ['name' => $impersonation->impersonator()->name]) }}
                </button>
            </form>
        </div>
    </div>
@endif

@if (session('impersonation_ended'))
    <div role="alert" data-test="impersonation-ended" class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-center text-sm font-medium text-ink">
        {{ session('impersonation_ended') }}
    </div>
@endif
