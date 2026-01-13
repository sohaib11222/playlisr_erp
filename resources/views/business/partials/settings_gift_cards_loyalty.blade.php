<div class="pos-tab-content">
    <div class="row">
        <div class="col-md-12">
            <h4>Gift Cards</h4>
            <p>
                <a href="{{ action('GiftCardController@index') }}" class="btn btn-primary">
                    <i class="fa fa-credit-card"></i> Manage Gift Cards
                </a>
            </p>
            <hr>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-12">
            <h4>Loyalty Tiers</h4>
            <p>
                <a href="{{ action('LoyaltyTierController@index') }}" class="btn btn-primary">
                    <i class="fa fa-star"></i> Manage Loyalty Tiers
                </a>
            </p>
            <p class="help-block">Configure loyalty tiers for your customers. Customers will be automatically assigned to tiers based on their lifetime purchase amounts.</p>
        </div>
    </div>
</div>

