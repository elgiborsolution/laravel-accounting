<?php

// Hasil penutupan bulanan global yang mudah dibaca. :period adalah nama bulan dan tahun;
// :reference sama dengan entri log aplikasi yang menyimpan detail teknis.
return [
    'errors' => [
        'not_due' => [
            'message' => ':period belum dapat ditutup sebelum :date.',
            'action' => 'Tunggu hingga tanggal penutupan, atau minta administrator meninjau pengaturan hari penutupan.',
        ],
        'reopened_period' => [
            'message' => ':period telah dibuka kembali, sehingga penutupan terjadwal melewatinya.',
            'action' => 'Tutup periode ini secara manual setelah koreksi selesai.',
        ],
        'unbalanced_journals' => [
            'message' => 'Jurnal yang sudah diposting untuk :period tidak seimbang (debit :debit, kredit :credit).',
            'action' => 'Perbaiki jurnal pada periode ini, lalu coba lagi.',
        ],
        'account_reference_mismatch' => [
            'message' => 'Saldo akun untuk :period tidak dapat disimpan karena akun di basis data perusahaan ini tidak sesuai dengan bagan akun.',
            'action' => 'Minta administrator memeriksa pengaturan data master akuntansi dan migrasinya, lalu coba lagi. Referensi: :reference.',
        ],
        'database_error' => [
            'message' => 'Basis data akuntansi perusahaan ini tidak dapat diperbarui saat menutup :period.',
            'action' => 'Coba lagi nanti. Jika masih terjadi, hubungi administrator dengan referensi :reference.',
        ],
        'tenant_unavailable' => [
            'message' => 'Data akuntansi perusahaan ini tidak dapat dibuka untuk menutup :period.',
            'action' => 'Pastikan perusahaan masih ada dan basis datanya tersedia, lalu coba lagi. Referensi: :reference.',
        ],
        'configuration_error' => [
            'message' => 'Pengaturan penutupan buku tidak valid atau belum lengkap, sehingga :period tidak dapat ditutup.',
            'action' => 'Minta administrator meninjau pengaturan penutupan akuntansi. Referensi: :reference.',
        ],
        'unexpected_error' => [
            'message' => ':period tidak dapat ditutup karena terjadi masalah yang tidak terduga.',
            'action' => 'Coba lagi nanti. Jika masih terjadi, hubungi administrator dengan referensi :reference.',
        ],
    ],
];
