import React, { useCallback, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import LayerPanel from '@/Components/Studio/LayerPanel';

export interface Layer {
    id: string;
    field: 'name' | 'ic' | 'group';
    label: string;
    x: number;           // percentage of image width
    y: number;           // percentage of image height
    fontSize: number;
    color: string;
    fontFamily: string;  // CSS font-family name (web-safe or registered via FontFace)
    fontPath: string | null; // server-side path for GD rendering
    align: 'left' | 'center' | 'right';
    groupFormat: 'vertical' | 'horizontal';
}

export interface FontOption {
    label: string;
    value: string;  // CSS font-family value
    path?: string;  // server-side absolute path for GD rendering; absent for system fonts
}

interface BatchData {
    id: string;
    template_path: string;
    global_settings: { layers?: Layer[] } | null;
}

interface RecordRow {
    id: number;
    recipient_name: string | null;
    identification_number: string | null;
    group_identifier: string | null;
    team_members: string[] | null;
}

interface Props {
    batch: BatchData;
    templateUrl: string;
    records: RecordRow[];
}

const DEFAULT_LAYERS: Layer[] = [
    { id: 'name',  field: 'name',  label: 'Participant Name', x: 50, y: 60, fontSize: 36, color: '#1E293B', fontFamily: 'Inter', fontPath: null, align: 'center', groupFormat: 'vertical' },
    { id: 'ic',    field: 'ic',    label: 'IC Number',        x: 50, y: 70, fontSize: 18, color: '#475569', fontFamily: 'Inter', fontPath: null, align: 'center', groupFormat: 'horizontal' },
    { id: 'group', field: 'group', label: 'Group / Team',     x: 50, y: 65, fontSize: 24, color: '#1E293B', fontFamily: 'Inter', fontPath: null, align: 'center', groupFormat: 'vertical' },
];

/** Maps alignment to a CSS transform so the (x,y) pin is the text anchor.
 *  'left'  → right edge at pin (text extends leftward)
 *  'center'→ center at pin
 *  'right' → left edge at pin (text extends rightward)
 */
export const alignTransform = (align: 'left' | 'center' | 'right'): string => {
    switch (align) {
        case 'left':   return 'translate(-100%, -50%)';
        case 'right':  return 'translate(0%, -50%)';
        default:       return 'translate(-50%, -50%)';
    }
};

const SYSTEM_FONTS: FontOption[] = [
    { label: 'Inter',            value: 'Inter' },
    { label: 'Arial',            value: 'Arial' },
    { label: 'Times New Roman',  value: 'Times New Roman' },
    { label: 'Georgia',          value: 'Georgia' },
    { label: 'Courier New',      value: 'Courier New' },
];

export default function Studio({ batch, templateUrl, records }: Props) {
    const [layers, setLayers] = useState<Layer[]>(
        batch.global_settings?.layers ?? DEFAULT_LAYERS,
    );
    const [activeLayer, setActiveLayer] = useState<string | null>(null);
    const [dragging, setDragging] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    // fontOptions: starts with system fonts, grows as user uploads custom fonts
    const [fontOptions, setFontOptions] = useState<FontOption[]>(SYSTEM_FONTS);
    // imageRef: bounds of the actual image wrapper — used for % coordinate math
    const imageRef = useRef<HTMLDivElement>(null);
    const dragOffset = useRef<{ dx: number; dy: number }>({ dx: 0, dy: 0 });

    const hasIc    = records.some((r) => r.identification_number);
    const hasGroup = records.some((r) => r.group_identifier);
    const visibleLayerIds = ['name', ...(hasIc ? ['ic'] : []), ...(hasGroup ? ['group'] : [])];
    const visibleLayers = layers.filter((l) => visibleLayerIds.includes(l.id));

    const updateLayer = (id: string, patch: Partial<Layer>) => {
        setLayers((prev) => prev.map((l) => (l.id === id ? { ...l, ...patch } : l)));
    };

    const handleMouseDown = (e: React.MouseEvent | React.TouchEvent, layerId: string) => {
        e.preventDefault();
        if ('stopPropagation' in e) e.stopPropagation();
        if (!imageRef.current) return;
        const rect = imageRef.current.getBoundingClientRect();
        const layer = layers.find((l) => l.id === layerId);
        if (!layer) return;
        const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
        const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
        const mouseXPct = ((clientX - rect.left) / rect.width) * 100;
        const mouseYPct = ((clientY - rect.top) / rect.height) * 100;
        dragOffset.current = { dx: mouseXPct - layer.x, dy: mouseYPct - layer.y };
        setActiveLayer(layerId);
        setDragging(layerId);
    };

    const handlePointerMove = useCallback(
        (e: React.MouseEvent<HTMLDivElement> | React.TouchEvent<HTMLDivElement>) => {
            if (!dragging || !imageRef.current) return;
            if ('touches' in e) e.preventDefault();
            const rect = imageRef.current.getBoundingClientRect();
            const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
            const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
            const rawX = ((clientX - rect.left) / rect.width) * 100;
            const rawY = ((clientY - rect.top) / rect.height) * 100;
            const x = parseFloat(Math.min(100, Math.max(0, rawX - dragOffset.current.dx)).toFixed(2));
            const y = parseFloat(Math.min(100, Math.max(0, rawY - dragOffset.current.dy)).toFixed(2));
            updateLayer(dragging, { x, y });
        },
        [dragging],
    );

    const handlePointerUp = () => setDragging(null);

    const csrf = (): string =>
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    const save = async () => {
        setSaving(true);
        try {
            await fetch(`/batch/${batch.id}/settings`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ global_settings: { layers } }),
            });
        } finally {
            setSaving(false);
        }
    };

    const proceed = async () => {
        await save();
        router.get(`/batch/${batch.id}/preview`);
    };
 
    
    return (
        <div className="min-h-screen bg-slate-50 flex flex-col">
            {/* Wizard step bar */}
            <div className="bg-white border-b border-slate-200 px-6 py-2 flex items-center gap-2 text-xs font-medium">
                {['Upload', 'Review Data', 'Design Studio', 'Preview & Export'].map((step, i) => (
                    <React.Fragment key={step}>
                        <span className={i === 2 ? 'text-indigo-600 font-semibold' : 'text-slate-400'}>
                            {i + 1}. {step}
                        </span>
                        {i < 3 && <span className="text-slate-300">/</span>}
                    </React.Fragment>
                ))}
            </div>

            {/* Toolbar */}
            <div className="flex items-center justify-between px-6 py-3 bg-white border-b border-slate-200">
                <h1 className="text-base font-semibold text-slate-900 tracking-tight">Design Studio</h1>
                <div className="flex gap-3">
                    <button
                        onClick={save}
                        disabled={saving}
                        className="px-4 py-2 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm font-medium disabled:opacity-50 transition-colors"
                    >
                        {saving ? 'Saving…' : 'Save'}
                    </button>
                    <button  
            
                        onClick={proceed}
                        className="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-colors"
                    >
                        Preview All →
                    </button>
                </div>
            </div>

            <div className="flex flex-col lg:flex-row flex-1 overflow-hidden">
                {/* Canvas — outer area captures pointer events (mouse + touch) */}
                <div
                    className="relative flex-1 min-h-0 overflow-hidden select-none bg-slate-100 flex items-center justify-center pb-[55vh] lg:pb-0"
                    onPointerMove={handlePointerMove}
                    onPointerUp={handlePointerUp}
                    onPointerLeave={handlePointerUp}
                >
                    {/* imageRef: scoped to the actual image dimensions for correct % math */}
                    <div
                        ref={imageRef}
                        className="relative shadow-md"
                        style={{ display: 'inline-block', touchAction: 'none' }}
                    >
                        <img
                            src={templateUrl}
                            alt="Certificate template"
                            style={{ display: 'block', maxWidth: '80vw', maxHeight: '75vh' }}
                            draggable={false}
                            onError={(e) => {
                                const el = e.currentTarget;
                                el.style.minWidth = '480px';
                                el.style.minHeight = '340px';
                                el.style.background = '#f1f5f9';
                                el.style.outline = '2px dashed #cbd5e1';
                                el.alt = 'Template image failed to load. Check storage:link.';
                            }}
                        />
                        {/* Overlay layers — positioned absolutely at (x%, y%) with alignment-based transform.
                             Font size shown proportional to canvas so it matches download output. */}
                        {visibleLayers.map((layer) => (
                            <div
                                key={layer.id}
                                onPointerDown={(e) => handleMouseDown(e, layer.id)}
                                style={{
                                    position: 'absolute',
                                    left: `${layer.x}%`,
                                    top: `${layer.y}%`,
                                    transform: layer.align === 'center'
                                        ? 'translate(-50%, -50%)'
                                        : layer.align === 'left'
                                        ? 'translate(0%, -50%)'
                                        : 'translate(-100%, -50%)',
                                    cursor: dragging === layer.id ? 'grabbing' : 'grab',
                                    userSelect: 'none',
                                    touchAction: 'none',
                                }}
                            >
                                <span
                                    style={{
                                        fontSize: `${layer.fontSize}px`,
                                        color: layer.color,
                                        fontFamily: `'${layer.fontFamily}', sans-serif`,
                                        whiteSpace: 'nowrap',
                                        outline: activeLayer === layer.id
                                            ? '2px solid #4F46E5'
                                            : '1px dashed rgba(79,70,229,0.4)',
                                        padding: '2px 8px',
                                        borderRadius: 3,
                                        background: 'rgba(255,255,255,0.65)',
                                        backdropFilter: 'blur(2px)',
                                        pointerEvents: 'none',
                                    }}
                                >
                                    [{layer.label}]
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Right Panel */}
                <LayerPanel
                    layers={visibleLayers}
                    activeId={activeLayer}
                    onSelect={setActiveLayer}
                    onUpdate={updateLayer}
                    onUpdateAll={(patch) => setLayers((prev) => prev.map((l) => ({ ...l, ...patch })))}
                    fontOptions={fontOptions}
                    onFontAdded={(opt) => setFontOptions((prev) => {
                        const idx = prev.findIndex((f) => f.value === opt.value);
                        if (idx >= 0) { const next = [...prev]; next[idx] = { ...next[idx], ...opt }; return next; }
                        return [...prev, opt];
                    })}
                />
            </div>
        </div>
    );
}
