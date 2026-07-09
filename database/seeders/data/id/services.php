<?php

return [
    ['service_code' => 'SALES_CASH', 'service_name' => 'Penjualan Tunai', 'module_name' => 'SALES', 'description' => 'Mencatat transaksi penjualan yang dibayar segera dengan kas atau setara kas.', 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'service_name' => 'Penjualan Kredit', 'module_name' => 'SALES', 'description' => 'Mencatat transaksi penjualan yang menimbulkan piutang pelanggan.', 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'service_name' => 'Retur Penjualan', 'module_name' => 'SALES', 'description' => 'Mencatat barang yang dikembalikan pelanggan dan membalik nilai penjualan terkait.', 'is_active' => true],
    ['service_code' => 'SALES_DISCOUNT', 'service_name' => 'Diskon Penjualan', 'module_name' => 'SALES', 'description' => 'Mencatat potongan harga yang diberikan kepada pelanggan atas transaksi penjualan.', 'is_active' => true],
    ['service_code' => 'SALES_WRITE_OFF', 'service_name' => 'Penghapusan Piutang Penjualan', 'module_name' => 'SALES', 'description' => 'Mencatat saldo terkait penjualan yang harus dihapus sesuai persetujuan kebijakan.', 'is_active' => true],

    ['service_code' => 'PURCHASE_CASH', 'service_name' => 'Pembelian Tunai', 'module_name' => 'PURCHASE', 'description' => 'Mencatat pembelian yang langsung diselesaikan menggunakan kas atau bank.', 'is_active' => true],
    ['service_code' => 'PURCHASE_CREDIT', 'service_name' => 'Pembelian Kredit', 'module_name' => 'PURCHASE', 'description' => 'Mencatat pembelian dari pemasok yang menimbulkan saldo utang.', 'is_active' => true],
    ['service_code' => 'PURCHASE_RETURN', 'service_name' => 'Retur Pembelian', 'module_name' => 'PURCHASE', 'description' => 'Mencatat barang yang dikembalikan ke pemasok dan membalik nilai pembelian terkait.', 'is_active' => true],

    ['service_code' => 'STOCK_OPENING', 'service_name' => 'Saldo Awal Persediaan', 'module_name' => 'INVENTORY', 'description' => 'Mencatat saldo persediaan awal saat setup atau migrasi periode pembukaan.', 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_PLUS', 'service_name' => 'Penyesuaian Persediaan Plus', 'module_name' => 'INVENTORY', 'description' => 'Mencatat kenaikan persediaan dari penyesuaian di luar alur pembelian normal.', 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_MINUS', 'service_name' => 'Penyesuaian Persediaan Minus', 'module_name' => 'INVENTORY', 'description' => 'Mencatat penurunan persediaan dari penyesuaian di luar alur penjualan normal.', 'is_active' => true],
    ['service_code' => 'STOCK_TRANSFER', 'service_name' => 'Transfer Persediaan', 'module_name' => 'INVENTORY', 'description' => 'Mencatat perpindahan persediaan antar gudang, lokasi, atau segmen stok.', 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_GAIN', 'service_name' => 'Selisih Persediaan Lebih', 'module_name' => 'INVENTORY', 'description' => 'Mencatat kelebihan persediaan yang ditemukan saat stok opname atau stock count.', 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_LOSS', 'service_name' => 'Selisih Persediaan Kurang', 'module_name' => 'INVENTORY', 'description' => 'Mencatat kekurangan persediaan yang ditemukan saat stok opname atau stock count.', 'is_active' => true],

    ['service_code' => 'CASH_IN', 'service_name' => 'Penerimaan Kas', 'module_name' => 'FINANCE', 'description' => 'Mencatat penerimaan kas non-penjualan seperti pendapatan lain atau setoran pemilik.', 'is_active' => true],
    ['service_code' => 'CASH_OUT', 'service_name' => 'Pengeluaran Kas', 'module_name' => 'FINANCE', 'description' => 'Mencatat pengeluaran kas non-pembelian seperti pembayaran operasional.', 'is_active' => true],
    ['service_code' => 'BANK_TRANSFER', 'service_name' => 'Transfer Bank', 'module_name' => 'FINANCE', 'description' => 'Mencatat transfer antara kas dan bank atau antar bank.', 'is_active' => true],
    ['service_code' => 'JOURNAL_MANUAL', 'service_name' => 'Jurnal Manual', 'module_name' => 'FINANCE', 'description' => 'Mewakili penyesuaian jurnal manual yang diinisiasi staf akuntansi.', 'is_active' => true],
    ['service_code' => 'PETTY_CASH', 'service_name' => 'Kas Kecil', 'module_name' => 'FINANCE', 'description' => 'Mencatat pendanaan, penggunaan, dan pengisian kembali kas kecil.', 'is_active' => true],

    ['service_code' => 'EXPENSE', 'service_name' => 'Beban', 'module_name' => 'EXPENSE', 'description' => 'Mencatat pengakuan beban operasional standar.', 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'service_name' => 'Beban Dibayar Dimuka', 'module_name' => 'EXPENSE', 'description' => 'Mencatat perolehan dan amortisasi beban dibayar dimuka.', 'is_active' => true],

    ['service_code' => 'PAYROLL', 'service_name' => 'Pembayaran Gaji', 'module_name' => 'PAYROLL', 'description' => 'Mencatat transaksi pembayaran gaji kepada karyawan dan pihak terkait.', 'is_active' => true],
    ['service_code' => 'PAYROLL_ACCRUAL', 'service_name' => 'Akrual Gaji', 'module_name' => 'PAYROLL', 'description' => 'Mencatat beban dan kewajiban gaji sebelum pembayaran.', 'is_active' => true],

    ['service_code' => 'ASSET_PURCHASE', 'service_name' => 'Pembelian Aset', 'module_name' => 'ASSET', 'description' => 'Mencatat perolehan aset tetap dan kapitalisasi terkait.', 'is_active' => true],
    ['service_code' => 'ASSET_DEPRECIATION', 'service_name' => 'Penyusutan Aset', 'module_name' => 'ASSET', 'description' => 'Mencatat beban penyusutan periodik dan akumulasi penyusutan.', 'is_active' => true],
    ['service_code' => 'ASSET_DISPOSAL', 'service_name' => 'Pelepasan Aset', 'module_name' => 'ASSET', 'description' => 'Mencatat penjualan, penghentian, atau pelepasan aset tetap.', 'is_active' => true],
    ['service_code' => 'ASSET_REVALUATION', 'service_name' => 'Revaluasi Aset', 'module_name' => 'ASSET', 'description' => 'Mencatat revaluasi aset yang disetujui naik maupun turun.', 'is_active' => true],

    ['service_code' => 'CUSTOMER_RECEIVABLE_PAYMENT', 'service_name' => 'Pembayaran Piutang Pelanggan', 'module_name' => 'ACCOUNT_RECEIVABLE', 'description' => 'Mencatat penerimaan pembayaran atas piutang pelanggan yang masih outstanding.', 'is_active' => true],
    ['service_code' => 'CUSTOMER_RECEIVABLE_WRITE_OFF', 'service_name' => 'Penghapusan Piutang Pelanggan', 'module_name' => 'ACCOUNT_RECEIVABLE', 'description' => 'Mencatat piutang pelanggan yang dihapus karena tidak tertagih.', 'is_active' => true],

    ['service_code' => 'VENDOR_PAYMENT', 'service_name' => 'Pembayaran Vendor', 'module_name' => 'ACCOUNT_PAYABLE', 'description' => 'Mencatat pembayaran keluar atas saldo utang vendor.', 'is_active' => true],
    ['service_code' => 'VENDOR_PAYABLE_WRITE_OFF', 'service_name' => 'Penghapusan Utang Vendor', 'module_name' => 'ACCOUNT_PAYABLE', 'description' => 'Mencatat saldo utang vendor yang dihapus setelah rekonsiliasi atau persetujuan.', 'is_active' => true],

    ['service_code' => 'TAX_OUTPUT', 'service_name' => 'Pajak Keluaran', 'module_name' => 'TAX', 'description' => 'Mencatat pajak keluaran yang timbul dari transaksi penjualan kena pajak.', 'is_active' => true],
    ['service_code' => 'TAX_INPUT', 'service_name' => 'Pajak Masukan', 'module_name' => 'TAX', 'description' => 'Mencatat pajak masukan yang timbul dari pembelian atau beban kena pajak.', 'is_active' => true],
    ['service_code' => 'TAX_PAYMENT', 'service_name' => 'Pembayaran Pajak', 'module_name' => 'TAX', 'description' => 'Mencatat pelunasan kewajiban pajak kepada otoritas pajak.', 'is_active' => true],

    ['service_code' => 'MONTH_END_CLOSING', 'service_name' => 'Tutup Buku Bulan', 'module_name' => 'CLOSING', 'description' => 'Mencatat penyesuaian dan pemindahan yang diperlukan saat tutup buku bulanan.', 'is_active' => true],
    ['service_code' => 'YEAR_END_CLOSING', 'service_name' => 'Tutup Buku Tahun', 'module_name' => 'CLOSING', 'description' => 'Mencatat jurnal penutup akhir tahun termasuk pemindahan laba ditahan.', 'is_active' => true],
];
