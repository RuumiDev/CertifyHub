import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { gsap } from 'gsap';
import type { Layer } from '@/Pages/Studio';
import MicroEditor from '@/Components/Preview/MicroEditor';

interface RecordRow {
    id: number;
    recipient_name: string;
    identification_number: string | null;
    group_identifier: string | null;
    override_settings: { layers?: Layer[] } | null;    team_members: string[] | null;}

interface BatchData {
    id: string;
    global_settings: { layers?: Layer[] } | null;
}

const STUDIO_BASE_WIDTH = 800;

interface Props {
    batch: BatchData;
    templateUrl: string;
    records: RecordRow[];
}

export default function Preview({ batch, templateUrl, records: initialRecords }: Props) {
    // Local copy so override saves update cards + MicroEditor re-open without page reload
    const [records, setRecords] = useState<RecordRow[]>(initialRecords);
    const [editingRecord, setEditingRecord] = useState<RecordRow | null>(null);

    // Export state — lives here so navbar and body can share it
    const [exportFormat, setExportFormat] = useState<'pdf' | 'png' | 'jpg'>('png');
    const [exportStatus, setExportStatus] = useState<'idle' | 'running' | 'done' | 'error'>('idle');
    const [exportProgress, setExportProgress] = useState({ completed: 0, total: 0, failed: 0 });
    const [dropdownOpen, setDropdownOpen] = useState(false);
    const dropdownRef = useRef<HTMLDivElement>(null);
    const progressBarRef = useRef<HTMLDivElement>(null);
    const [uiPct, setUiPct] = useState(0);

    // Close dropdown on outside click
    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
                setDropdownOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    // Re-register custom fonts on mount — FontFace API registrations don't survive
    // page navigation, so we reload them from the saved fontPath on the server.
    useEffect(() => {
        const fontMap = new Map<string, string>(); // fontFamily -> storage path

        const collect = (layers: Layer[] | undefined) => {
            if (!layers) return;
            for (const l of layers) {
                if (l.fontPath && l.fontFamily && !fontMap.has(l.fontFamily)) {
                    fontMap.set(l.fontFamily, l.fontPath);
                }
            }
        };

        collect(batch.global_settings?.layers);
        for (const record of records) {
            collect(record.override_settings?.layers);
        }

        fontMap.forEach(async (fontPath, fontFamily) => {
            if (document.fonts.check(`12px "${fontFamily}"`)) return; // already loaded
            try {
                const fontFace = new FontFace(fontFamily, `url('/storage/${fontPath}')`);
                await fontFace.load();
                document.fonts.add(fontFace);
            } catch {
                // Font file unavailable — thumbnails fall back to sans-serif silently
            }
        });
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const handleOverrideSaved = (recordId: number, settings: { layers: Layer[] }) => {
        // Patch local records so the card thumbnail and next MicroEditor open reflect the saved override
        setRecords(prev => prev.map(r =>
            r.id === recordId
                ? { ...r, override_settings: { layers: settings.layers } }
                : r
        ));
        setEditingRecord(null);
    };

    const executeExport = async (fmt: 'pdf' | 'png' | 'jpg') => {
        setExportFormat(fmt);
        setDropdownOpen(false);
        setExportStatus('running');
        setExportProgress({ completed: 0, total: records.length, failed: 0 });
        setUiPct(0);

        // Reset GSAP progress bar to 0 before starting
        if (progressBarRef.current) {
            gsap.set(progressBarRef.current, { width: '0%' });
        }

        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
        const res = await fetch(`/batch/${batch.id}/execute`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ export_format: fmt }),
        });
        if (!res.ok) { setExportStatus('error'); return; }

        const interval = setInterval(async () => {
            const r = await fetch(`/batch/${batch.id}/progress`);
            const data = await r.json();
            const newPct = data.total > 0 ? Math.round((data.completed / data.total) * 100) : 0;

            setExportProgress({ completed: data.completed, total: data.total, failed: data.failed ?? 0 });

            // GSAP smooth progress bar animation
            if (progressBarRef.current) {
                gsap.to(progressBarRef.current, {
                    width: `${newPct}%`,
                    duration: 0.4,
                    ease: 'power2.out',
                    onUpdate() {
                        const el = progressBarRef.current;
                        if (el) {
                            const current = parseFloat(el.style.width) || 0;
                            setUiPct(Math.floor(current));
                        }
                    },
                });
            } else {
                setUiPct(newPct);
            }

            if (data.zip_ready) {
                clearInterval(interval);
                setUiPct(100);
                if (progressBarRef.current) {
                    gsap.to(progressBarRef.current, { width: '100%', duration: 0.3, ease: 'power2.out' });
                }
                setExportStatus('done');
            } else if (data.done && data.completed === 0) {
                // All records failed — no ZIP will be created
                clearInterval(interval);
                setExportStatus('error');
            }
        }, 1000);
    };

    const pct = exportProgress.total > 0 ? Math.round((exportProgress.completed / exportProgress.total) * 100) : 0;
    // uiPct is the GSAP-animated display value; pct is the true server value

    return (
        <div className="min-h-screen bg-slate-50">
            {/* Wizard step bar */}
            <div className="bg-white border-b border-slate-200 px-6 py-2 flex items-center gap-2 text-xs font-medium">
                {['Upload', 'Review Data', 'Design Studio', 'Preview & Export'].map((step, i) => (
                    <React.Fragment key={step}>
                        <span className={i === 3 ? 'text-indigo-600 font-semibold' : 'text-slate-400'}>
                            {i + 1}. {step}
                        </span>
                        {i < 3 && <span className="text-slate-300">/</span>}
                    </React.Fragment>
                ))}
            </div>

            {/* Toolbar */}
            <div className="sticky top-0 z-10 flex items-center justify-between px-6 py-3 bg-white border-b border-slate-200 shadow-sm">
                <div className="flex items-center gap-4">
                    <button
                        onClick={() => router.get(`/batch/${batch.id}/studio`)}
                        className="px-4 py-2 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm font-medium transition-colors cursor-pointer"
                    >
                        ← Back to Studio
                    </button>
                    <div>
                        <h1 className="text-base font-semibold text-slate-900 tracking-tight">Preview All Certificates</h1>
                        <p className="text-slate-500 text-xs mt-0.5">{records.length} certificates · Click any to fine-tune</p>
                    </div>
                </div>

                {/* Export controls — all in navbar */}
                <div className="flex items-center gap-2">
                    {/* Inline progress bar while running */}
                    {exportStatus === 'running' && (
                        <div className="flex items-center gap-2.5 px-4 py-2 bg-indigo-50 border border-indigo-200 rounded-lg">
                            <div className="w-28 h-1.5 bg-indigo-100 rounded-full overflow-hidden">
                                <div
                                    ref={progressBarRef}
                                    className="h-full bg-indigo-500 rounded-full"
                                    style={{ width: '0%' }}
                                />
                            </div>
                            <span className="text-xs text-indigo-600 font-medium tabular-nums w-8 text-right">{uiPct}%</span>
                            {exportProgress.failed > 0 && (
                                <span className="text-[10px] text-red-400 font-medium">{exportProgress.failed} failed</span>
                            )}
                        </div>
                    )}

                    {/* Download button after completion */}
                    {exportStatus === 'done' && (
                        <a
                            href={`/batch/${batch.id}/download`}
                            download
                            className="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold transition-colors shadow-sm"
                        >
                            ↓ Download .zip
                        </a>
                    )}

                    {/* Error retry */}
                    {exportStatus === 'error' && (
                        <button
                            onClick={() => setExportStatus('idle')}
                            className="px-4 py-2 rounded-lg bg-red-50 border border-red-200 text-red-600 text-sm font-medium hover:bg-red-100 transition-colors cursor-pointer"
                        >
                            ⚠ Retry
                        </button>
                    )}

                    {/* Export dropdown — visible when idle or done (re-export) */}
                    {exportStatus !== 'running' && (
                        <div className="relative" ref={dropdownRef}>
                            <button
                                onClick={() => setDropdownOpen(v => !v)}
                                className="flex items-center gap-2 px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-colors shadow-sm cursor-pointer"
                            >
                                Export
                                <svg className={`w-3.5 h-3.5 transition-transform duration-150 ${dropdownOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            {dropdownOpen && (
                                <div className="absolute right-0 top-full mt-1.5 w-48 bg-white border border-slate-200 rounded-xl shadow-xl py-1.5 z-20 overflow-hidden">
                                    {([
                                        { fmt: 'pdf', label: 'PDF', sub: 'Print · saved as PNG' },
                                        { fmt: 'png', label: 'PNG', sub: 'Image · transparent' },
                                        { fmt: 'jpg', label: 'JPG', sub: 'Image · compressed' },
                                    ] as const).map(({ fmt, label, sub }) => (
                                        <button
                                            key={fmt}
                                            onClick={() => executeExport(fmt)}
                                            className="w-full flex items-center justify-between px-4 py-2.5 hover:bg-indigo-50 text-left transition-colors cursor-pointer group"
                                        >
                                            <span className="font-mono font-bold text-slate-900 group-hover:text-indigo-700 text-sm tracking-wide">.{label}</span>
                                            <span className="text-slate-400 text-xs group-hover:text-indigo-400">{sub}</span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Certificate grid — responsive bento matrix */}
            <div className="w-full max-w-7xl mx-auto grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 px-6 py-6 pb-12">
                {records.map((record) => {
                    const layers: Layer[] = record.override_settings?.layers ?? batch.global_settings?.layers ?? [];
                    const displayName = record.group_identifier
                        ? `${record.group_identifier} (${record.team_members?.length ?? 0} members)`
                        : (record.recipient_name ?? '');

                    return (
                        <button
                            key={record.id}
                            onClick={() => setEditingRecord(record)}
                            className="relative group rounded-xl overflow-hidden border border-slate-200 hover:border-indigo-400 transition-colors bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
                        >
                            {/* Template thumbnail — container query so font-size scales with card width */}
                                <div className="relative aspect-[4/3] overflow-hidden bg-slate-100" style={{ containerType: 'inline-size' }}>
                                <img
                                    src={templateUrl}
                                    alt={displayName}
                                    className="w-full h-full object-cover"
                                />
                                {/* Overlay text preview */}
                                {layers.map((layer) => {
                                    // Font size as fraction of canvas width, expressed as cqw so it
                                    // scales with the thumbnail card — matches backend scaling formula.
                                    const fontPct = (layer.fontSize / STUDIO_BASE_WIDTH) * 100; // % of container width
                                    let textNode: React.ReactNode;

                                    if (layer.field === 'name' && record.team_members && record.team_members.length > 0) {
                                        if (layer.groupFormat === 'vertical') {
                                            textNode = (
                                                <div style={{ display: 'flex', flexDirection: 'column', alignItems: layer.align === 'center' ? 'center' : layer.align === 'right' ? 'flex-end' : 'flex-start', gap: `${fontPct * 0.4}cqw` }}>
                                                    {record.team_members.map((m, i) => (
                                                        <span key={i} style={{ fontSize: `${fontPct}cqw`, lineHeight: 1 }}>{m}</span>
                                                    ))}
                                                </div>
                                            );
                                        } else {
                                            textNode = record.team_members.join(', ');
                                        }
                                    } else {
                                        const rawText = layer.field === 'name'
                                            ? (record.recipient_name ?? '')
                                            : layer.field === 'ic'
                                            ? (record.identification_number ?? '')
                                            : (record.group_identifier ?? '');
                                        if (!rawText) return null;
                                        textNode = rawText;
                                    }

                                    return (
                                        <div
                                            key={layer.id}
                                            style={{
                                                position: 'absolute',
                                                left: `${layer.x}%`,
                                                top: `${layer.y}%`,
                                                transform: layer.align === 'center'
                                                    ? 'translate(-50%, -50%)'
                                                    : layer.align === 'left'
                                                    ? 'translate(0%, -50%)'
                                                    : 'translate(-100%, -50%)',
                                                fontSize: `${fontPct}cqw`,
                                                color: layer.color,
                                                fontFamily: `'${layer.fontFamily}', sans-serif`,
                                                whiteSpace: layer.groupFormat === 'vertical' ? 'normal' : 'nowrap',
                                                textAlign: layer.align,
                                                pointerEvents: 'none',
                                            }}
                                        >
                                            {textNode}
                                        </div>
                                    );
                                })}

                                {/* Hover overlay */}
                                <div className="absolute inset-0 bg-indigo-500/20 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <span className="text-white text-xs font-semibold bg-indigo-600 px-2 py-1 rounded">
                                        Edit
                                    </span>
                                </div>
                            </div>
                            <div className="p-2 text-left">
                                <p className="text-xs text-slate-700 font-medium truncate">{displayName}</p>
                                {record.override_settings && (
                                    <span className="text-xs text-indigo-600">Custom</span>
                                )}
                            </div>
                        </button>
                    );
                })}
            </div>

            {/* Micro-editor modal */}
            {editingRecord && (
                <MicroEditor
                    batchId={batch.id}
                    // Pass the up-to-date record from local state (reflects previously saved overrides)
                    record={records.find(r => r.id === editingRecord.id) ?? editingRecord}
                    templateUrl={templateUrl}
                    globalLayers={batch.global_settings?.layers ?? []}
                    onClose={() => setEditingRecord(null)}
                    onSaved={handleOverrideSaved}
                />
            )}
        </div>
    );
}

