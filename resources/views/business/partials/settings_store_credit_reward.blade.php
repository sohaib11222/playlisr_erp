<div class="pos-tab-content">
<div class="row well">
    <div class="col-sm-12">
        <h4>Store Credit Rewards</h4>
        <p class="help-block">
            Automatically grant customers store credit for spending. Store credit used to
            pay for a sale does not count toward earning more. Walk-in customers are excluded.
        </p>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            <div class="checkbox">
                <label>
                {!! Form::checkbox('enable_spend_credit_reward', 1, !empty($business->enable_spend_credit_reward),
                    ['class' => 'input-icheck', 'id' => 'enable_spend_credit_reward']); !!} Enable store credit rewards
                </label>
            </div>
        </div>
    </div>

    <div class="clearfix"></div>
    <div class="col-sm-4">
        <div class="form-group">
            {!! Form::label('spend_credit_reward_amount', 'Store credit granted ($):') !!}
            {!! Form::text('spend_credit_reward_amount', @num_format($business->spend_credit_reward_amount ?? 5), ['class' => 'form-control input_number', 'placeholder' => '5']); !!}
            <p class="help-block">Amount of store credit to grant per reward bracket.</p>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            {!! Form::label('spend_credit_reward_per', 'Per pre-tax spend of ($):') !!}
            {!! Form::text('spend_credit_reward_per', @num_format($business->spend_credit_reward_per ?? 100), ['class' => 'form-control input_number', 'placeholder' => '100']); !!}
            <p class="help-block">Every full amount of qualifying pre-tax spend earns one reward.</p>
        </div>
    </div>

    <div class="clearfix"></div>
    <div class="col-sm-12">
        <p class="help-block">
            <strong>Example:</strong> at $5 per $100, a $250 pre-tax sale paid without store credit
            earns $10 (two full $100 brackets); paid with $60 store credit + cash, it earns $5
            (qualifying spend $190).
        </p>
    </div>
</div>
</div>
