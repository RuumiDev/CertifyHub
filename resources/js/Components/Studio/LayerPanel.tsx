import React, { useRef, useState } from 'react';
import type { Layer, FontOption } from '@/Pages/Studio';
import axios from 'axios';

interface Props {
    layers: Layer[];
    activeId: string | null;
    onSelect: (id: string) => void;
    onUpdate: (id: string, patch: Partial<Layer>) => void;
    onUpdateAll: (patch: Partial<Layer>) => void;  // apply patch to every layer (used on font upload)
    fontOptions: FontOption[];
    onFontAdded: (opt: FontOption) => void;
}

export default function LayerPanel({ layers, activeId, onSelect, onUpdate, onUpdateAll, fontOptions, onFontAdded }: Props) {
    const active = layers.find((l) => l.id === activeId) ?? null;
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [fontError, setFontError] = useState<string | null>(null);

    const handleFontUpload = async (file: File) => {
        setFontError(null);
        setUploading(true);
        try {
            // 1. Register font in the browser via FontFace API — powers canvas preview immediately
            const fontName = file.name.replace(/\.[^/.]+$/, '');
            const arrayBuffer = await file.arrayBuffer();
            const fontFace = new FontFace(fontName, arrayBuffer);
            await fontFace.load();
            document.fonts.add(fontFace);

            // Apply new font to ALL layers so every text field uses the same typeface.
            // This matches the expected UX: uploading one font = global font change.
            onUpdateAll({ fontFamily: fontName });

            // 2. Soft-persist to server for GD back-end rendering (best-effort)
            //    A server rejection won't break the canvas — fontPath just stays null.
            let serverPath: string | null = null;
            try {
                const formData = new FormData();
                formData.append('font', file);
                const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
                const { data } = await axios.post<{ path: string; url: string; name: string }>(
                    '/fonts/upload',
                    formData,
                    { headers: { 'X-CSRF-TOKEN': csrfToken } },
                );
                serverPath = data.path;
                // Apply fontPath to ALL layers too
                onUpdateAll({ fontPath: serverPath });
            } catch {
                // Server upload failed — canvas preview still works, GD rendering will use fallback
                console.warn('Font server upload failed; canvas preview active but server-side rendering will use fallback font.');
            }

            // Register in dropdown with path so other layers inherit it when selected
            onFontAdded({ label: fontName, value: fontName, ...(serverPath ? { path: serverPath } : {}) });
        } catch {
            setFontError('Could not load font. Ensure the file is a valid TTF, OTF, WOFF, or WOFF2.');
        } finally {
            setUploading(false);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    return (
        <aside className="w-72 bg-white border-l border-slate-200 flex flex-col overflow-y-auto">
            {/* Layer list */}
            <div className="p-4 border-b border-slate-200">
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Layers</p>
                <div className="flex flex-col gap-1">
                    {layers.map((layer) => (
                        <button
                            key={layer.id}
                            onClick={() => onSelect(layer.id)}
                            className={`text-left px-3 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer ${
                                activeId === layer.id
                                    ? 'bg-indigo-600 text-white'
                                    : 'text-slate-700 hover:bg-slate-100'
                            }`}
                        >
                            {layer.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Layer properties */}
            {active ? (
                <div className="p-4 flex flex-col gap-4">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        {active.label}
                    </p>

                    {/* Font family */}
                    <label className="flex flex-col gap-1.5">
                        <span className="text-xs text-slate-500">Font Family</span>
                        <select
                            value={active.fontFamily}
                            onChange={(e) => {
                                const selectedOpt = fontOptions.find((f) => f.value === e.target.value);
                                onUpdate(active.id, { fontFamily: e.target.value, fontPath: selectedOpt?.path ?? null });
                            }}
                            className="bg-white border border-slate-200 text-slate-900 text-sm rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            style={{ fontFamily: `'${active.fontFamily}', sans-serif` }}
                        >
                            {fontOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}
                                    style={{ fontFamily: `'${opt.value}', sans-serif` }}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                        {/* Custom font upload */}
                        <div className="flex items-center gap-2 mt-1">
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".ttf,.otf,.woff,.woff2"
                                className="hidden"
                                onChange={(e) => {
                                    const f = e.target.files?.[0];
                                    if (f) handleFontUpload(f);
                                }}
                            />
                            <button
                                onClick={() => fileInputRef.current?.click()}
                                disabled={uploading}
                                className="flex-1 py-1.5 text-xs rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium transition-colors disabled:opacity-50 cursor-pointer"
                            >
                                {uploading ? 'Uploading…' : '+ Upload custom font'}
                            </button>
                        </div>
                        {fontError && (
                            <p className="text-xs text-red-600 mt-0.5">{fontError}</p>
                        )}
                    </label>

                    {/* Font size */}
                    <div className="flex flex-col gap-1.5">
                        <span className="text-xs text-slate-500">Font Size</span>
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => onUpdate(active.id, { fontSize: Math.max(8, active.fontSize - 1) })}
                                className="w-7 h-7 flex items-center justify-center rounded bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm font-bold transition-colors cursor-pointer"
                            >−</button>
                            <input
                                type="number"
                                min={8}
                                max={120}
                                value={active.fontSize}
                                onChange={(e) => onUpdate(active.id, { fontSize: Math.min(120, Math.max(8, Number(e.target.value))) })}
                                className="flex-1 bg-white border border-slate-200 text-slate-900 text-sm rounded-lg px-2 py-1 text-center focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums"
                            />
                            <button
                                onClick={() => onUpdate(active.id, { fontSize: Math.min(120, active.fontSize + 1) })}
                                className="w-7 h-7 flex items-center justify-center rounded bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm font-bold transition-colors cursor-pointer"
                            >+</button>
                            <span className="text-xs text-slate-400 ml-0.5">px</span>
                        </div>
                    </div>

                    {/* Color */}
                    <label className="flex flex-col gap-1.5">
                        <span className="text-xs text-slate-500">Text Color</span>
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                value={active.color}
                                onChange={(e) => onUpdate(active.id, { color: e.target.value })}
                                className="w-8 h-8 rounded cursor-pointer border border-slate-200 bg-transparent"
                            />
                            <span className="text-xs font-mono text-slate-500">{active.color}</span>
                        </div>
                    </label>

                    {/* Alignment */}
                    <label className="flex flex-col gap-1.5">
                        <span className="text-xs text-slate-500">Alignment</span>
                        <div className="flex gap-1">
                            {(['left', 'center', 'right'] as const).map((align) => (
                                <button
                                    key={align}
                                    onClick={() => onUpdate(active.id, { align })}
                                    className={`flex-1 py-1.5 text-xs rounded font-medium transition-colors cursor-pointer ${
                                        active.align === align
                                            ? 'bg-indigo-600 text-white'
                                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                    }`}
                                >
                                    {align.charAt(0).toUpperCase() + align.slice(1)}
                                </button>
                            ))}
                        </div>
                    </label>

                    {/* Group format — controls how team_members array is rendered in the name position */}
                    {active.field === 'name' && (
                        <label className="flex flex-col gap-1.5">
                            <span className="text-xs text-slate-500">Group Format</span>
                            <p className="text-[10px] text-slate-400 -mt-1">How team member names are laid out</p>
                            <div className="flex gap-1">
                                {(['vertical', 'horizontal'] as const).map((fmt) => (
                                    <button
                                        key={fmt}
                                        onClick={() => onUpdate(active.id, { groupFormat: fmt })}
                                        className={`flex-1 py-1.5 text-xs rounded font-medium transition-colors cursor-pointer ${
                                            active.groupFormat === fmt
                                                ? 'bg-indigo-600 text-white'
                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                        }`}
                                    >
                                        {fmt === 'vertical' ? 'Vertical Stack' : 'Comma-Joined'}
                                    </button>
                                ))}
                            </div>
                        </label>
                    )}

                    {/* Position inputs with ±0.1% nudge */}
                    <div className="flex flex-col gap-2">
                        {(['x', 'y'] as const).map((axis) => (
                            <div key={axis} className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">{axis.toUpperCase()} (%)</span>
                                <div className="flex items-center gap-1">
                                    <button
                                        onClick={() => onUpdate(active.id, { [axis]: parseFloat(Math.max(0, active[axis] - 0.1).toFixed(1)) })}
                                        className="w-7 h-7 flex items-center justify-center rounded bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm font-bold transition-colors cursor-pointer"
                                    >−</button>
                                    <input
                                        type="number"
                                        min={0}
                                        max={100}
                                        step={0.1}
                                        value={active[axis].toFixed(1)}
                                        onChange={(e) => onUpdate(active.id, { [axis]: parseFloat(parseFloat(e.target.value).toFixed(1)) })}
                                        className="flex-1 bg-white border border-slate-200 text-slate-900 text-sm rounded-lg px-2 py-1 text-center focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums"
                                    />
                                    <button
                                        onClick={() => onUpdate(active.id, { [axis]: parseFloat(Math.min(100, active[axis] + 0.1).toFixed(1)) })}
                                        className="w-7 h-7 flex items-center justify-center rounded bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm font-bold transition-colors cursor-pointer"
                                    >+</button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <div className="p-4 text-sm text-slate-400">
                    Select a layer to edit its properties.
                </div>
            )}
        </aside>
    );
}

