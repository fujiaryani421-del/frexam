<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $title }} — FRExam</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet"><style>:root{font-family:Inter,sans-serif;color:#1f2937}*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:30px 20px;background:linear-gradient(135deg,#f4f7fb,#e8eef7)}.wrap{max-width:1180px;margin:auto}.nav{display:flex;gap:9px;flex-wrap:wrap}.nav a{padding:10px 12px;border-radius:10px;background:#fffd;border:1px solid #fff;color:#23395d;text-decoration:none;font-weight:800;font-size:12px}.hero{margin:18px 0;padding:28px 30px;border-radius:22px;background:linear-gradient(115deg,#23395d,#365d92);color:#fff;position:relative;overflow:hidden}.hero:after{content:"✦";position:absolute;right:28px;top:-47px;font-size:165px;color:#ffffff1a}.hero>*{position:relative}.hero p{margin:0;color:#e7effa}.hero h1{margin:7px 0;font-size:28px}.card{padding:21px;background:#fffd;border:1px solid #fff;border-radius:18px;box-shadow:0 14px 30px #23395d12}.count{display:inline-block;margin-bottom:14px;padding:6px 9px;border-radius:20px;background:#e8eef7;color:#365d92;font-size:12px;font-weight:800}.list{display:grid;grid-template-columns:repeat(3,1fr);gap:11px}.item{padding:14px;border:1px solid #e2ebf3;border-radius:12px;background:#f8fafc;color:#23395d;font-weight:700;word-break:break-word}.item small{display:block;margin-top:6px;color:#64748b;font-weight:400}.item.link{display:block;text-decoration:none;transition:.2s}.item.link:hover{transform:translateY(-2px);background:#e8eef7;box-shadow:0 8px 15px #23395d14}.empty{padding:30px;text-align:center;color:#64748b;background:#f8fafc;border-radius:12px}@media(max-width:780px){.list{grid-template-columns:1fr 1fr}}@media(max-width:500px){body{padding:20px 14px}.list{grid-template-columns:1fr}}</style></head><body><main class="wrap"><nav class="nav"><a href="{{ route('admin.overview') }}">← Pusat Kendali</a><a href="{{ route('admin.users') }}">Manajemen Pengguna</a><a href="{{ route('admin.attempts') }}">Riwayat Ujian</a></nav><section class="hero"><p>{{ strtoupper($group) }}</p><h1>{{ $title }}</h1><p>{{ $description }}</p></section><section class="card"><span class="count">{{ $items->count() }} data</span>
@if($items->isEmpty())
<p class="empty">Belum ada data untuk ditampilkan.</p>
@else
<div class="list">
@foreach($items as $item)
@if(($page === 'scores' || $page === 'exam-report') && ! is_string($item))
<a class="item link" href="{{ route('admin.exam-score-report', $item->id) }}"><strong>{{ $item->title }}</strong><small>{{ $item->subject }} · {{ $item->participant_count }} peserta · Klik untuk rekap nilai →</small></a>
@else
<article class="item">
@if(is_string($item))
{{ $item }}
@else
<strong>{{ $item->title ?? $item->name ?? $item->subject ?? $item->event_label ?? $item->event ?? 'Data tersedia' }}</strong><small>{{ $item->subject ?? $item->class_name ?? $item->status ?? ($item->starts_at ?? $item->occurred_at ?? '') }}</small>
@endif
</article>
@endif
@endforeach
</div>
@endif
</section></main></body></html>
