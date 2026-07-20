import React, { useCallback, useRef, useState } from 'react';
import type { Layer, FontOption } from '@/Pages/Studio';
import LayerPanel from '@/Components/Studio/LayerPanel';
import { XMarkIcon } from '@heroicons/react/24/outline';

const SYSTEM_FONTS: FontOption[] = [
    { label: 'Inter',           value: 'Inter' },
    { label: 'Arial',           value: 'Arial' },
    { label: 'Times New Roman', value: 'Times New Roman' },
    { label: 'Georgia',         value: 'Georgia' },
    { label: 'Courier New',     value: 'Courier New' },
];

/** Maps alignment to a CSS transform so the (x,y) pin is the text anchor.
 *  'left'   → left edge at pin (text extends rightward)  — translate(0%, -50%)
 *  'center' → center at pin                               — translate(-50%, -50%)
 *  'right'  → right edge at pin (text extends leftward)  — translate(-100%, -50%)
 */
const alignTransform = (align: 'left' | 'center' | 'right') => {
    switch (align) {
        case 'left':   return 'translate(0%, -50%)';
        case 'right':  return 'translate(-100%, -50%)';
        default:       return 'translate(-50%, -50%)';
    }
};

interface RecordRow {
    id: number;
    recipient_name: string | null;
    identification_number: string | null;
    group_identifier: string | null;
    team_members: string[] | null;
    override_settings: { layers?: Layer[] } | null;
}

interface Props {
    batchId: string;
    record: RecordRow;
    templateUrl: string;
    globalLayers: Layer[];
    onClose: () => void;
    onSaved: (recordId: number, settings: { layers: Layer[] }) => void;
}

export default function MicroEditor({ batchId, record, templateUrl, globalLayers, onClose, onSaved }: Props) {
    // Defensive filter: exclude layers whose data field is absent for this record
    const initialLayers = (record.override_settings?.layers ?? globalLayers).filter((l) => {
        if (l.field === 'ic'    && record.identification_number === null) return false;
        if (l.field === 'group' && record.group_identifier === null)      return false;
        return true;
    });

    const [layers, setLayers]         = useState<Layer[]>(initialLayers);
    const [activeLayer, setActiveLayer] = useState<string | null>(null);
    const [dragging, setDragging]     = useState<string | null>(null);
    const [saving, setSaving]         = useState(false);
    const [saveError, setSaveError]   = useState<string | null>(null);
    const [fontOptions, setFontOptions] = useState<FontOption[]>(SYSTEM_FONTS);

    // imageRef wraps the <img> exactly — % coords are relative to this element's bounds
    const imageRef  = useRef<HTMLDivElement>(null);
    const dragOffset = useRef<{ dx: number; dy: number }>({ dx: 0, dy: 0 });

    const [canvasWidth, setCanvasWidth] = useState(800);

    React.useEffect(() => {
        if (!imageRef.current) return;
        const observer = new ResizeObserver((entries) => {
            for (const entry of entries) {
                if (entry.contentRect.width > 0) {
                    setCanvasWidth(entry.contentRect.width);
                }
            }
        });
        observer.observe(imageRef.current);
        return () => observer.disconnect();
    }, []);

    const updateLayer = (id: string, patch: Partial<Layer>) =>
        setLayers((prev) => prev.map((l) => (l.id === id ? { ...l, ...patch } : l)));

    const handlePointerDown = (e: React.PointerEvent | React.TouchEvent, layerId: string) => {
        e.preventDefault();
        e.stopPropagation();
        if (!imageRef.current) return;
        const rect  = imageRef.current.getBoundingClientRect();
        const layer = layers.find((l) => l.id === layerId);
        if (!layer) return;
        const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
        const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
        const pctX = ((clientX - rect.left) / rect.width)  * 100;
        const pctY = ((clientY - rect.top)  / rect.height) * 100;
        dragOffset.current = { dx: pctX - layer.x, dy: pctY - layer.y };
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

    const save = async () => {
        setSaving(true);
        setSaveError(null);
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
        try {
            const res = await fetch(`/batch/${batchId}/records/${record.id}/override`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ override_settings: { layers } }),
            });
            if (!res.ok) {
                const msg = await res.text().catch(() => `HTTP ${res.status}`);
                setSaveError(`Save failed: ${msg}`);
                return;
            }
            onSaved(record.id, { layers });
        } catch (err) {
            setSaveError('Network error — check connection and retry.');
        } finally {
            setSaving(false);
        }
    };

    const displayName = record.group_identifier ?? record.recipient_name;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
            <div className="relative bg-white border border-slate-200 rounded-xl w-full max-w-5xl max-h-[90vh] flex flex-col shadow-xl overflow-hidden">
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-3 border-b border-slate-200 bg-slate-50">
                    <div>
                        <h2 className="text-sm font-semibold text-slate-900">Micro-Edit: {displayName}</h2>
                        <p className="text-xs text-slate-500">Override positions and sizing for this certificate only.</p>
                        {saveError && <p className="text-xs text-red-600 mt-0.5">{saveError}</p>}
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={save}
                            disabled={saving}
                            className="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold disabled:opacity-50 transition-colors cursor-pointer"
                        >
                            {saving ? 'Saving…' : 'Save Override'}
                        </button>
                        <button onClick={onClose} className="p-1.5 rounded-lg text-slate-400 hover:bg-slate-100 transition-colors cursor-pointer">
                            <XMarkIcon className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Body */}
                <div className="flex flex-col lg:flex-row flex-1 min-h-0 overflow-hidden">
                    {/* Canvas — outer div captures pointer events (mouse + touch) */}
                    <div
                        className="relative flex-1 overflow-hidden bg-slate-100 flex items-center justify-center"
                        onPointerMove={handlePointerMove}
                        onPointerUp={handlePointerUp}
                        onPointerLeave={handlePointerUp}
                    >
                        {/* imageRef: inline-block so it matches the rendered image exactly */}
                        <div ref={imageRef} className="relative shadow-md" style={{ display: 'inline-block', touchAction: 'none' }}>
                            <img
                                src={templateUrl}
                                alt="Template"
                                style={{ display: 'block', maxWidth: '100%', maxHeight: '60vh' }}
                                draggable={false}
                            />
                            {layers.map((layer) => {
                                const text = layer.field === 'name'
                                    ? (record.team_members?.length
                                        ? (layer.groupFormat === 'horizontal'
                                            ? record.team_members.join(', ')
                                            : record.team_members.join(' / '))
                                        : (record.recipient_name ?? ''))
                                    : layer.field === 'ic'
                                    ? (record.identification_number ?? '')
                                    : (record.group_identifier ?? '');
                                return (
                                    <div
                                        key={layer.id}
                                        onPointerDown={(e) => handlePointerDown(e, layer.id)}
                                        style={{
                                            position:  'absolute',
                                            left:      `${layer.x}%`,
                                            top:       `${layer.y}%`,
                                            transform: layer.align === 'center'
                                                ? 'translate(-50%, -50%)'
                                                : layer.align === 'left'
                                                ? 'translate(0%, -50%)'
                                                : 'translate(-100%, -50%)',
                                            cursor:    dragging === layer.id ? 'grabbing' : 'grab',
                                            userSelect: 'none',
                                        }}
                                    >
                                        <span
                                            style={{
                                                fontSize:       `${(layer.fontSize / 800) * canvasWidth}px`,
                                                color:          layer.color,
                                                fontFamily:     `'${layer.fontFamily}', sans-serif`,
                                                whiteSpace:     'nowrap',
                                                outline:        activeLayer === layer.id
                                                    ? '2px solid #4F46E5'
                                                    : '1px dashed rgba(79,70,229,0.4)',
                                                padding:        '2px 6px',
                                                borderRadius:   3,
                                                background:     'rgba(255,255,255,0.65)',
                                                backdropFilter: 'blur(2px)',
                                                pointerEvents:  'none',
                                            }}
                                        >
                                            {text || `[${layer.label}]`}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Panel */}
                    <LayerPanel
                        layers={layers}
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
        </div>
    );
}

