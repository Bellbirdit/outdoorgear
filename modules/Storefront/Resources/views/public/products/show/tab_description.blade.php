<div id="description" class="tab-pane description custom-page-content active">
    <div
        x-ref="descriptionContent" 
        class="content"
        :class="{ 
            active: showDescriptionContent,
            'less-content': !showMore }
        "
    >
        
        <!-- <h2 class="section-title">Description</h2> -->

        {!! $product->description !!}
      
 
      
        {{-- Key Features --}}
        @if (!empty($product->key_features))
            <h2 class="desc-section-title">Key Features</h2>
            <div class="key-features-section">
                {!! $product->key_features !!}
            </div>
        @endif

        {{-- Technical Specs --}}
        @if (!empty($product->technical_specs))
            <h2 class="desc-section-title">Technical Specs</h2>
            <div class="technical-specs-section">
                {!! $product->technical_specs !!}
            </div>
        @endif

        {{-- Why Choose --}}
        @if (!empty($product->why_choose))
            <h2 class="desc-section-title">Why Choose</h2>
            <div class="why-choose-section">
                {!! $product->why_choose !!}
            </div>
        @endif

        {{-- Tips Guide --}}
        @if (!empty($product->tips_guide))
            <h2 class="desc-section-title">Tips Guide</h2>
            <div class="tips-guide-section">
                {!! $product->tips_guide !!}
            </div>
        @endif
    </div>

    <button
        x-cloak
        type="button"
        class="btn btn-default btn-show-more"
        :class="{ 'show': showMore }"
        @click="toggleDescriptionContent"
        x-text="
            showDescriptionContent ?
            '{{ trans('storefront::product.show_less') }}' :
            '{{ trans('storefront::product.show_more') }}'
        "
    >
    </button>
</div>
