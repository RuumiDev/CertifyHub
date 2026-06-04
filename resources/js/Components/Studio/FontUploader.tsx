import React, { useRef } from 'react';
import type { Layer } from '@/Pages/Studio';

interface Props {
    batchId: string;
    activeLayer: Layer | null;
    onFontSaved: (path: string) => void;
}

export default function FontUploader({ batchId, activeLayer, onFontSaved }: Props) {
    const inputRef = useRef<HTMLInputElement>(null);

    const handleChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const form = new FormData();
        form.append('font', file);

        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
        const res = await fetch('/fonts/upload', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            body: form,
        });

        if (res.ok) {
            const json = await res.json();
            onFontSaved(json.path);
        }
    };

    return (
        <div>
            <input
                ref={inputRef}
                type="file"
                accept=".ttf,.woff,.woff2"
                className="hidden"
                onChange={handleChange}
            />
            <button
                onClick={() => inputRef.current?.click()}
                disabled={!activeLayer}
                title={activeLayer ? `Upload font for "${activeLayer.label}"` : 'Select a layer first'}
                className="px-4 py-2 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
            >
                Upload Font
            </button>
        </div>
    );
}
