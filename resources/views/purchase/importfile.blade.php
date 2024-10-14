<form action="{{ route('purchases.store-excel') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <!-- Your existing input fields -->

    <!-- Add the Excel file input -->
    <div>
        <label for="purchase_excel">Upload Purchase Excel File:</label>
        <input type="file" name="purchase_excel" accept=".xlsx, .xls, .csv">
    </div>

    <button type="submit">Submit</button>
</form>
