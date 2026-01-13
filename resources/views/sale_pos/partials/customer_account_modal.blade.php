<!-- Customer Account Details Modal -->
<div class="modal fade" id="customer_account_modal" tabindex="-1" role="dialog" aria-labelledby="customerAccountModalLabel">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="customerAccountModalLabel">Customer Account Details</h4>
            </div>
            <div class="modal-body">
                <div id="customer_account_loading" style="text-align: center; padding: 20px;">
                    <i class="fa fa-spinner fa-spin fa-2x"></i>
                    <p>Loading customer information...</p>
                </div>
                <div id="customer_account_content" style="display: none;">
                    <div class="row">
                        <div class="col-md-12">
                            <h4 id="modal_customer_name"></h4>
                        </div>
                    </div>
                    
                    <div class="row" style="margin-top: 20px;">
                        <div class="col-md-6">
                            <div class="box box-primary">
                                <div class="box-header">
                                    <h3 class="box-title">Account Summary</h3>
                                </div>
                                <div class="box-body">
                                    <table class="table">
                                        <tr>
                                            <td><strong>Account Balance:</strong></td>
                                            <td class="text-danger" id="modal_account_balance">$0.00</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Lifetime Purchases:</strong></td>
                                            <td id="modal_lifetime_purchases">$0.00</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Loyalty Points:</strong></td>
                                            <td id="modal_loyalty_points">0</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Loyalty Tier:</strong></td>
                                            <td id="modal_loyalty_tier">Bronze</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Last Purchase:</strong></td>
                                            <td id="modal_last_purchase_date">Never</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="box box-success">
                                <div class="box-header">
                                    <h3 class="box-title">Gift Cards</h3>
                                </div>
                                <div class="box-body">
                                    <div id="modal_gift_cards_list">
                                        <p class="text-muted">No active gift cards</p>
                                    </div>
                                    <div style="margin-top: 10px;">
                                        <strong>Total Gift Card Balance:</strong> 
                                        <span class="text-success" id="modal_total_gift_card_balance">$0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" style="margin-top: 20px;">
                        <div class="col-md-12">
                            <div class="box box-info">
                                <div class="box-header">
                                    <h3 class="box-title">Recent Purchases</h3>
                                </div>
                                <div class="box-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Invoice #</th>
                                                    <th>Date</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody id="modal_recent_purchases_list">
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No recent purchases</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


