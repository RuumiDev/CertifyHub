import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { PlusIcon, TrashIcon, CheckIcon } from '@heroicons/react/24/outline';

interface RecordRow {
    id?: number;
    recipient_name: string | null;
    identification_number: string | null;
    group_identifier: string | null;
    team_members: string[] | null;
    generation_status: string;
}

interface BatchData {
    id: string;
    template_path: string;
    export_format: string;
    global_settings: Record<string, unknown> | null;
}

interface Props {
    batch: BatchData;
    records: RecordRow[];
}

export default function Validate({ batch, records: initialRecords }: Props) {
    const [rows, setRows] = useState<RecordRow[]>(initialRecords);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    const updateRow = (idx: number, key: keyof RecordRow, value: string) => {
        setRows((prev) => {
            const next = [...prev];
            next[idx] = { ...next[idx], [key]: value || null };
            return next;
        });
        setSaved(false);
    };

    const addRow = () => {
        setRows((prev) => [
            ...prev,
            { recipient_name: '', identification_number: null, group_identifier: null, team_members: null, generation_status: 'pending' },
        ]);
        setSaved(false);
    };

    const removeRow = (idx: number) => {
        setRows((prev) => prev.filter((_, i) => i !== idx));
        setSaved(false);
    };

    const save = async (): Promise<boolean> => {
        setSaving(true);
        try {
            const res = await fetch(`/batch/${batch.id}/records`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                },
                body: JSON.stringify({ records: rows }),
            });
            if (!res.ok) {
                throw new Error('Failed to save records');
            }
            const data = await res.json();
            if (data.records) {
                setRows(data.records);
            }
            setSaved(true);
            return true;
        } catch (err) {
            console.error(err);
            setSaved(false);
            return false;
        } finally {
            setSaving(false);
        }
    };

    const proceed = async () => {
        const ok = await save();
        if (ok) {
            router.get(`/batch/${batch.id}/studio`);
        }
    };

    const layout = (row: RecordRow): 'identified' | 'grouped' | 'standard' => {
        if (row.group_identifier) return 'grouped';
        if (row.identification_number) return 'identified';
        return 'standard';
    };

    // Bento stats — computed from current rows state
    const stats = useMemo(() => ({
        total:      rows.length,
        standard:   rows.filter((r) => layout(r) === 'standard').length,
        identified: rows.filter((r) => layout(r) === 'identified').length,
        grouped:    rows.filter((r) => layout(r) === 'grouped').length,
    }), [rows]);

    const layoutBadge: Record<string, string> = {
        standard:   'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
        identified: 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200',
        grouped:    'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
    };

    return (
        <div className="min-h-screen bg-slate-50">
            {/* Wizard step bar */}
            <div className="bg-white border-b border-slate-200 px-6 py-2 flex items-center gap-2 text-xs font-medium">
                {['Upload', 'Review Data', 'Design Studio', 'Preview & Export'].map((step, i) => (
                    <React.Fragment key={step}>
                        <span className={i === 1 ? 'text-indigo-600 font-semibold' : 'text-slate-400'}>
                            {i + 1}. {step}
                        </span>
                        {i < 3 && <span className="text-slate-300">/</span>}
                    </React.Fragment>
                ))}
            </div>

            <div className="max-w-6xl mx-auto px-6 py-8">
                {/* Page header */}
                <div className="flex items-start justify-between mb-8">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-900 tracking-tight">Data Integrity Review</h1>
                        <p className="text-slate-500 mt-1 text-sm">
                            {rows.length} records parsed from CSV. Edit inline, then proceed to the design studio.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={save}
                            disabled={saving}
                            className="px-4 py-2 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm font-medium transition-colors disabled:opacity-50"
                        >
                            {saved ? (
                                <span className="flex items-center gap-1.5 text-emerald-600">
                                    <CheckIcon className="w-4 h-4" /> Saved
                                </span>
                            ) : saving ? 'Saving…' : 'Save Changes'}
                        </button>
                        <button
                            onClick={proceed}
                            className="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-colors"
                        >
                            Design Studio →
                        </button>
                    </div>
                </div>

                {/* ── Bento Stats Grid (PRD §2.2) ── */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                    {[
                        { label: 'Total Records',       value: stats.total,      accent: 'border-slate-300',   num: 'text-slate-900' },
                        { label: 'Standard',            value: stats.standard,   accent: 'border-slate-300',   num: 'text-slate-700' },
                        { label: 'Identified (IC)',     value: stats.identified, accent: 'border-indigo-300',  num: 'text-indigo-600' },
                        { label: 'Grouped / Team',      value: stats.grouped,    accent: 'border-emerald-300', num: 'text-emerald-600' },
                    ].map((card) => (
                        <div
                            key={card.label}
                            className={`bg-white rounded-xl border-t-2 ${card.accent} border border-slate-200 p-5 shadow-sm`}
                        >
                            <p className="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">{card.label}</p>
                            <p className={`text-3xl font-semibold tabular-nums ${card.num}`}>{card.value}</p>
                        </div>
                    ))}
                </div>

                {/* ── Data Table ── */}
                <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50">
                                    <th className="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3 w-10">#</th>
                                    <th className="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">Participant Name / Members</th>
                                    <th className="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">IC Number</th>
                                    <th className="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">Group / Team</th>
                                    <th className="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">Layout</th>
                                    <th className="px-4 py-3 w-10"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((row, idx) => (
                                    <tr key={idx} className="hover:bg-slate-50/70 transition-colors">
                                        <td className="px-4 py-3 text-slate-400 text-xs tabular-nums">{idx + 1}</td>
                                        <td className="px-4 py-3">
                                            {row.team_members && row.team_members.length > 0 ? (
                                                <div className="flex flex-wrap gap-1">
                                                    {row.team_members.map((m, i) => (
                                                        <span key={i} className="bg-emerald-50 text-emerald-700 text-xs px-1.5 py-0.5 rounded ring-1 ring-emerald-200">{m}</span>
                                                    ))}
                                                </div>
                                            ) : (
                                                <input
                                                    type="text"
                                                    value={row.recipient_name ?? ''}
                                                    onChange={(e) => updateRow(idx, 'recipient_name', e.target.value)}
                                                    placeholder="Name…"
                                                    className="w-full bg-transparent text-slate-900 placeholder-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-500 rounded px-1 py-0.5"
                                                />
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <input
                                                type="text"
                                                value={row.identification_number ?? ''}
                                                onChange={(e) => updateRow(idx, 'identification_number', e.target.value)}
                                                placeholder="—"
                                                className="w-full bg-transparent text-slate-600 placeholder-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-500 rounded px-1 py-0.5 font-mono text-xs"
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <input
                                                type="text"
                                                value={row.group_identifier ?? ''}
                                                onChange={(e) => updateRow(idx, 'group_identifier', e.target.value)}
                                                placeholder="—"
                                                className="w-full bg-transparent text-slate-600 placeholder-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-500 rounded px-1 py-0.5"
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${layoutBadge[layout(row)]}`}>
                                                {layout(row)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <button
                                                onClick={() => removeRow(idx)}
                                                className="text-slate-300 hover:text-red-500 transition-colors cursor-pointer"
                                            >
                                                <TrashIcon className="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                                
                    {/* Add row */}
                    <div className="border-t border-slate-100 p-4">
                        <button
                            onClick={addRow}
                            className="flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-500 transition-colors font-medium cursor-pointer"
                        >
                            <PlusIcon className="w-4 h-4" />
                            Add participant row 
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
