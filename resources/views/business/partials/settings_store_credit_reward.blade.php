<div class="pos-tab-content">
<div class="row well">
    <div class="col-sm-12">
        <h4>Store Credit Rewards</h4>
        <p class="help-block">
            Customers earn store credit as their spending adds up. Each customer accrues a
            running total of qualifying pre-tax spend, and earns the reward below for every
            full bracket they cross ($100 &rarr; $200 &rarr; $300 &hellip;). Store credit used
            to pay for a sale does not count toward earning more, and walk-in customers are
            excluded. Only spending on or after the start date counts.
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
            <p class="help-block">Amount of store credit granted per bracket reached.</p>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            {!! Form::label('spend_credit_reward_per', 'For every cumulative spend of ($):') !!}
            {!! Form::text('spend_credit_reward_per', @num_format($business->spend_credit_reward_per ?? 100), ['class' => 'form-control input_number', 'placeholder' => '100']); !!}
            <p class="help-block">Bracket size — each full bracket of cumulative qualifying pre-tax spend earns one reward.</p>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            {!! Form::label('spend_reward_start_date', 'Rewards start date:') !!}
            {!! Form::text('spend_reward_start_date', !empty($business->spend_reward_start_date) ? \Carbon\Carbon::parse($business->spend_reward_start_date)->format('Y-m-d') : '', ['class' => 'form-control', 'placeholder' => 'YYYY-MM-DD']); !!}
            <p class="help-block">Only purchases on or after this date accrue. Leave blank to count all history.</p>
        </div>
    </div>

    <div class="clearfix"></div>
    <div class="col-sm-12">
        <p class="help-block">
            <strong>Example:</strong> at $5 per $100, a customer who spends $70 then $70 (no store
            credit used) reaches $140 cumulative &mdash; that's one full $100 bracket, so they earn
            $5. Their next $5 comes when their running total passes $200.
        </p>
    </div>
</div>
</div>
