import React from 'react';
import { useForm } from '@inertiajs/react';
import DropZone from '@/Components/DropZone';

export default function Landing() {
    const { data, setData, post, processing, errors } = useForm<{
        template: File | null;
        csv: File | null;
    }>({
        template: null,
        csv: null,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/batch', { forceFormData: true });
    };

    const ready = data.template !== null && data.csv !== null;
    const hasErrors = Object.keys(errors).length > 0;

    return (
        <div className="min-h-screen bg-slate-50 flex flex-col">
            {/* Top bar */}
            <header className="flex items-center justify-between px-10 py-5 bg-white border-b border-slate-200 shadow-sm">
                <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center shadow-sm">
                        <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <span className="text-lg font-bold tracking-tight text-slate-900">Certify<span className="text-indigo-600">Hub</span></span>
                </div>
                {/* Wizard pill */}
                <div className="hidden sm:flex items-center gap-2 bg-slate-100 rounded-full px-5 py-2">
                    {['Upload', 'Review', 'Design', 'Export'].map((step, i) => (
                        <React.Fragment key={step}>
                            <span className={`flex items-center gap-1.5 text-xs font-medium ${i === 0 ? 'text-indigo-700' : 'text-slate-400'}`}>
                                <span className={`inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold ${
                                    i === 0 ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-500'
                                }`}>{i + 1}</span>
                                {step}
                            </span>
                            {i < 3 && <span className="text-slate-300">›</span>}
                        </React.Fragment>
                    ))}
                </div>
            </header>

            {/* Hero */}
            <div className="px-8 pt-10 pb-6 max-w-5xl mx-auto w-full">
                <p className="text-xs font-semibold uppercase tracking-widest text-indigo-500 mb-2">Batch Certificate Generator</p>
                <h1 className="text-3xl font-bold tracking-tight text-slate-900">
                    Upload your template &amp; dataset
                </h1>
                <p className="text-slate-500 text-sm mt-1">
                    Drop a certificate image and a CSV file — CertifyHub handles the rest.
                </p>
            </div>

            {/* Server error banner */}
            {hasErrors && (
                <div className="mx-8 mb-2 max-w-5xl mx-auto w-full">
                    <div className="p-4 bg-red-50 border border-red-200 rounded-xl flex flex-col gap-1">
                        <p className="text-sm font-semibold text-red-700">Please fix the following:</p>
                        {Object.values(errors).map((msg, i) => (
                            <p key={i} className="text-xs text-red-600">— {msg}</p>
                        ))}
                    </div>
                </div>
            )}

            {/* Bento Grid */}
            <form onSubmit={handleSubmit} className="px-8 pb-10 max-w-5xl mx-auto w-full">
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

                    {/* Box 1 — Template upload (7 cols) */}
                    <div className="lg:col-span-7 bg-white border border-slate-200 rounded-2xl p-6 flex flex-col gap-3 shadow-sm hover:border-indigo-300 transition-colors">
                        <div className="flex items-center gap-2 mb-1">
                            <span className="flex items-center justify-center w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </span>
                            <span className="text-xs font-semibold uppercase tracking-widest text-slate-500">Certificate Template</span>
                        </div>
                        <DropZone
                            accept=".png,.jpg,.jpeg"
                            label="Drop template image here"
                            hint="PNG / JPG / JPEG — max 20 MB"
                            file={data.template}
                            onFile={(file) => setData('template', file)}
                            error={errors.template}
                        />
                    </div>

                    {/* Right column — 5 cols */}
                    <div className="lg:col-span-5 flex flex-col gap-4">

                        {/* Box 2 — CSV upload */}
                        <div className="bg-white border border-slate-200 rounded-2xl p-6 flex flex-col gap-3 shadow-sm hover:border-indigo-300 transition-colors">
                            <div className="flex items-center gap-2 mb-1">
                                <span className="flex items-center justify-center w-6 h-6 rounded-lg bg-emerald-50 text-emerald-600">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </span>
                                <span className="text-xs font-semibold uppercase tracking-widest text-slate-500">Participant Dataset</span>
                            </div>
                            <DropZone
                                accept=".csv,.txt"
                                label="Drop CSV file here"
                                hint="CSV or TXT — Excel not supported"
                                file={data.csv}
                                onFile={(file) => setData('csv', file)}
                                error={errors.csv}
                            />
                        </div>

                        {/* Box 3 — CSV Format Schema Table */}
                        <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col gap-3 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-widest text-slate-400">Expected CSV Columns</p>

                            {/* Header row */}
                            <div className="grid grid-cols-12 gap-2 border-b border-slate-700 pb-2">
                                <span className="col-span-4 text-[9px] font-semibold uppercase tracking-widest text-slate-500">Column Header</span>
                                <span className="col-span-4 text-[9px] font-semibold uppercase tracking-widest text-slate-500">Variants</span>
                                <span className="col-span-4 text-[9px] font-semibold uppercase tracking-widest text-slate-500">Example</span>
                            </div>

                            {/* Data rows */}
                            {([
                                {
                                    col: 'recipient_name',
                                    required: true,
                                    variants: 'name, full_name',
                                    example: 'Ahmad Akmal Bin Abdullah',
                                },
                                {
                                    col: 'identification_number',
                                    required: false,
                                    variants: 'ic_number, id',
                                    example: '040916-08-XXXX',
                                },
                                {
                                    col: 'group_identifier',
                                    required: false,
                                    variants: 'team, group_name',
                                    example: 'Team Innovara',
                                },
                            ] as const).map(({ col, required, variants, example }) => (
                                <div key={col} className="grid grid-cols-12 gap-2 py-2 border-b border-slate-800 last:border-0 items-start">
                                    {/* Col 1: column name + required badge */}
                                    <div className="col-span-4 flex items-start gap-1.5 min-w-0">
                                        <span className={`shrink-0 mt-[5px] w-1.5 h-1.5 rounded-full ${required ? 'bg-indigo-400' : 'bg-slate-600'}`} />
                                        <div className="min-w-0">
                                            <code className="block text-[10px] text-indigo-300 font-mono tracking-tight leading-tight break-all">{col}</code>
                                            <span className={`block text-[9px] font-medium mt-0.5 ${required ? 'text-indigo-400' : 'text-slate-500'}`}>
                                                {required ? 'required' : 'optional'}
                                            </span>
                                        </div>
                                    </div>
                                    {/* Col 2: accepted variants */}
                                    <p className="col-span-4 text-[10px] text-slate-500 font-mono tracking-tight leading-snug break-all">{variants}</p>
                                    {/* Col 3: example value */}
                                    <p className="col-span-4 text-[10px] text-slate-400 tracking-tight leading-snug break-words">{example}</p>
                                </div>
                            ))}

                            <p className="text-[9px] text-slate-600 pt-1">First row = header. UTF-8 encoding recommended.</p>
                        </div>
                    </div>
                </div>

                {/* Submit */}
                <div className="mt-5 flex items-center justify-between gap-4">
                    <p className="text-xs text-slate-400">No account needed · Files processed locally</p>
                    <button
                        type="submit"
                        disabled={!ready || processing}
                        className="px-8 py-3 rounded-xl font-semibold text-white text-sm transition-all
                                   bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed
                                   focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-50 cursor-pointer shadow-sm"
                    >
                        {processing ? 'Uploading & Parsing…' : 'Proceed to Data Review →'}
                    </button>
                </div>
            </form>
        </div>
    );
}

