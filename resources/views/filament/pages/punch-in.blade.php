<x-filament-panels::page>
    <div
        x-data="{
            currentTime: new Date().toLocaleTimeString(),
            timer: null,
            init() {
                this.timer = setInterval(() => this.currentTime = new Date().toLocaleTimeString(), 1000)
            },
            destroy() { clearInterval(this.timer) },
        }"
        x-text="currentTime"
        class="text-3xl font-mono text-gray-700 dark:text-gray-300 mb-8"
    >
        {{ now()->format('h:i:s A') }}
    </div>

    <div class="flex flex-col items-center justify-center min-h-screen py-12">
        <div class="text-center space-y-6">
            <h1 class="text-4xl font-bold text-primary-600">Welcome!</h1>
            <p class="text-xl text-gray-600 dark:text-gray-400">
                Click the button above to punch in/out for today.
            </p>

            <p class="mt-8 text-sm text-gray-500">
                Make sure to allow location access when prompted.
            </p>
        </div>
    </div>

</x-filament-panels::page>
