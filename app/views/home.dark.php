<extends:layout.base title="Hellowin" stage="{{ $state['stage'] }}"/>

<stack:push name="scripts">
    <script src="https://cdn.jsdelivr.net/npm/centrifuge@5.7.4/dist/centrifuge.js"></script>
    <script src="/js/app.js"></script>
</stack:push>

<define:body>
    <main class="stage" id="app"
          data-centrifugo-url="{{ $centrifugoUrl }}"
          data-clicks="{{ $state['clicks'] }}"
          data-threshold="{{ $state['threshold'] }}">
        <div class="moon" aria-hidden="true"></div>

        <a class="help-link" href="/how-it-works" title="Как это работает" aria-label="Как это работает">
            <span aria-hidden="true">🎃</span><span class="help-link__mark" aria-hidden="true">?</span>
        </a>

        @foreach($elements as $element)
            <button type="button" class="element element--{{ $element['type'] }}"
                    data-element="{{ $element['id'] }}"
                    data-type="{{ $element['type'] }}"
                    style="left: {{ $element['x'] }}%; top: {{ $element['y'] }}%"
                    aria-label="{{ $element['type'] }} {{ $element['id'] }}">🎃</button>
        @endforeach

        <footer class="hud">
            <span class="hud__status" data-status="disconnected">disconnected</span>
            <span class="hud__progress">
                <span data-progress-clicks>{{ $state['clicks'] }}</span>
                / <span data-progress-threshold>{{ $state['threshold'] }}</span>
            </span>
            <progress data-progress max="{{ $state['threshold'] }}" value="{{ $state['clicks'] }}"></progress>
        </footer>
    </main>
</define:body>
