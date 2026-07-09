# Simulasi Akuntansi Penjualan dengan PPN 11%

Dokumen ini berisi dua skenario umum transaksi penjualan dengan PPN:

1. Penjualan Tunai
2. Penjualan Kredit (Piutang)

---

# Kasus 1 - Penjualan Tunai

## Data Transaksi

| Keterangan | Nilai |
|------------|-------:|
| Harga Jual | Rp10.000.000 |
| PPN 11% | Rp1.100.000 |
| Total Dibayar Customer | Rp11.100.000 |
| Harga Pokok Penjualan (HPP) | Rp6.000.000 |

---

## 1. Penjualan

### Jurnal

```text
Dr Kas                           11.100.000
    Cr Penjualan                           10.000.000
    Cr Hutang PPN Keluaran                  1.100.000
```

### Jurnal HPP

```text
Dr Harga Pokok Penjualan          6.000.000
    Cr Persediaan                            6.000.000
```

---

## 2. Bayar PPN

Diasumsikan tidak ada PPN Masukan.

```text
Dr Hutang PPN Keluaran            1.100.000
    Cr Kas                                   1.100.000
```

---

## Laporan Laba Rugi

| Akun | Nilai |
|------|-------:|
| Penjualan | 10.000.000 |
| HPP | (6.000.000) |
| **Laba Bersih** | **4.000.000** |

> PPN tidak masuk ke laporan laba rugi karena merupakan kewajiban kepada pemerintah.

---

## Neraca Setelah Semua Transaksi

### Aset

| Akun | Nilai |
|------|-------:|
| Kas | 10.000.000 |
| Persediaan | Berkurang 6.000.000 |

### Liabilitas

| Akun | Nilai |
|------|-------:|
| Hutang PPN Keluaran | 0 |

### Ekuitas

| Akun | Nilai |
|------|-------:|
| Laba Berjalan | 4.000.000 |

---

# Kasus 2 - Penjualan Kredit (Piutang)

## Data Transaksi

| Keterangan | Nilai |
|------------|-------:|
| Harga Jual | Rp10.000.000 |
| PPN 11% | Rp1.100.000 |
| Total Invoice | Rp11.100.000 |
| HPP | Rp6.000.000 |

---

## Tahap 1 - Penjualan Kredit

### Jurnal Penjualan

```text
Dr Piutang Usaha                 11.100.000
    Cr Penjualan                           10.000.000
    Cr Hutang PPN Keluaran                  1.100.000
```

### Jurnal HPP

```text
Dr Harga Pokok Penjualan          6.000.000
    Cr Persediaan                            6.000.000
```

---

## Posisi Setelah Penjualan

### Neraca

#### Aset

| Akun | Nilai |
|------|-------:|
| Piutang Usaha | 11.100.000 |

#### Liabilitas

| Akun | Nilai |
|------|-------:|
| Hutang PPN Keluaran | 1.100.000 |

---

## Laporan Laba Rugi

| Akun | Nilai |
|------|-------:|
| Penjualan | 10.000.000 |
| HPP | (6.000.000) |
| **Laba Bersih** | **4.000.000** |

> Walaupun pelanggan belum membayar, pendapatan sudah diakui menggunakan basis akrual.

---

## Tahap 2 - Bayar PPN

Misalkan pelanggan belum melunasi piutang.

Perusahaan tetap wajib menyetor PPN.

```text
Dr Hutang PPN Keluaran            1.100.000
    Cr Kas                                   1.100.000
```

### Neraca Setelah Bayar Pajak

#### Aset

| Akun | Nilai |
|------|-------:|
| Kas | (1.100.000) |
| Piutang Usaha | 11.100.000 |

#### Liabilitas

| Akun | Nilai |
|------|-------:|
| Hutang PPN Keluaran | 0 |

---

## Tahap 3 - Customer Melunasi Piutang

```text
Dr Kas                           11.100.000
    Cr Piutang Usaha                        11.100.000
```

---

## Neraca Akhir

### Aset

| Akun | Nilai |
|------|-------:|
| Kas | 10.000.000 |
| Piutang Usaha | 0 |

### Liabilitas

| Akun | Nilai |
|------|-------:|
| Hutang PPN Keluaran | 0 |

### Ekuitas

| Akun | Nilai |
|------|-------:|
| Laba Berjalan | 4.000.000 |

---

# Alur Akuntansi Penjualan Kredit

```text
Invoice Dibuat
│
├── Dr Piutang Usaha ............ 11.100.000
├── Cr Penjualan ................ 10.000.000
└── Cr Hutang PPN ...............  1.100.000

        │
        ▼

PPN Harus Disetor

Dr Hutang PPN ................. 1.100.000
    Cr Kas ....................... 1.100.000

        │
        ▼

Customer Membayar

Dr Kas ...................... 11.100.000
    Cr Piutang Usaha ........... 11.100.000
```

---

# Konsep Akuntansi

## Penjualan

- Diakui sebesar nilai sebelum PPN.
- Menambah pendapatan perusahaan.

## Hutang PPN Keluaran

- Bukan pendapatan.
- Merupakan kewajiban kepada pemerintah.
- Dicatat sebagai **Current Liability**.

## Piutang Usaha

- Hak perusahaan untuk menagih pelanggan.
- Nilainya termasuk PPN.

## Kas

- Bertambah saat pelanggan membayar.
- Berkurang saat PPN disetor.

---

# Mapping COA yang Direkomendasikan

| Akun | Account Type |
|------|--------------|
| Kas | Asset |
| Piutang Usaha | Asset |
| Persediaan | Asset |
| Penjualan | Revenue |
| Harga Pokok Penjualan | Expense |
| Hutang PPN Keluaran | Current Liability |
| PPN Masukan | Current Asset |
| Hutang Dagang | Current Liability |

---

# Flow Journal untuk Accounting Package

## Penjualan Tunai

```text
Kas
    Penjualan
    Hutang PPN

Harga Pokok Penjualan
    Persediaan
```

## Penjualan Kredit

```text
Piutang Usaha
    Penjualan
    Hutang PPN

Harga Pokok Penjualan
    Persediaan
```

## Pelunasan Piutang

```text
Kas
    Piutang Usaha
```

## Pembayaran PPN

```text
Hutang PPN
    Kas
```

---

# Kesimpulan

1. **PPN bukan merupakan pendapatan perusahaan**, sehingga tidak masuk ke laporan laba rugi.
2. **PPN Keluaran selalu dicatat sebagai Hutang PPN Keluaran**, baik penjualan dilakukan secara tunai maupun kredit.
3. **Piutang Usaha dicatat sebesar nilai invoice (termasuk PPN)** karena itulah jumlah yang harus dibayar pelanggan.
4. **Pendapatan diakui sebesar nilai sebelum PPN**.
5. **Pembayaran piutang tidak memengaruhi laba rugi**, hanya mengubah Piutang Usaha menjadi Kas.
6. **Pembayaran PPN hanya mengurangi Hutang PPN Keluaran dan Kas**, tanpa memengaruhi laba rugi.

Dokumen ini dapat dijadikan acuan implementasi engine jurnal pada package accounting agar setiap transaksi menghasilkan jurnal yang konsisten, lengkap, dan sesuai dengan prinsip akuntansi berbasis akrual.