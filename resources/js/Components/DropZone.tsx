import React, { useCallback, useRef, useState } from 'react';
import { CloudArrowUpIcon, DocumentIcon, ExclamationCircleIcon } from '@heroicons/react/24/outline';

interface Props {
    accept: string;          // e.g. ".png,.jpg" or ".csv"
    label: string;
    hint: string;
    file: File | null;
    onFile: (file: File) => void;
    error?: string;
}

/** Return allowed extensions from an accept string like ".png,.jpg,.jpeg" */
function allowedExts(accept: string): string[] {
    return accept.split(',').map((s) => s.trim().toLowerCase());
}

export default function DropZone({ accept, label, hint, file, onFile, error }: Props) {
    const [dragging, setDragging] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const tryFile = useCallback(
        (f: File) => {
            const ext = '.' + f.name.split('.').pop()!.toLowerCase();
            if (!allowedExts(accept).includes(ext)) {
                setLocalError(`"${f.name}" is not allowed. Please upload: ${accept}`);
                return;
            }
            setLocalError(null);
            onFile(f);
        },
        [accept, onFile],
    );

    const handleDrop = useCallback(
        (e: React.DragEvent<HTMLDivElement>) => {
            e.preventDefault();
            setDragging(false);
            const dropped = e.dataTransfer.files[0];
            if (dropped) tryFile(dropped);
        },
        [tryFile],
    );

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const selected = e.target.files?.[0];
        if (selected) tryFile(selected);
    };

    const displayError = localError ?? error;
    const hasError = Boolean(displayError);

    return (
        <div className="flex flex-col gap-1.5">
            <div
                onClick={() => inputRef.current?.click()}
                onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                className={[
                    'flex flex-col items-center justify-center min-h-[180px] rounded-xl border-2 border-dashed',
                    'cursor-pointer transition-all duration-200 select-none',
                    dragging
                        ? 'border-indigo-500 bg-indigo-50'
                        : hasError
                        ? 'border-red-400 bg-red-50/40'
                        : file
                        ? 'border-emerald-400 bg-emerald-50/50'
                        : 'border-slate-300 bg-slate-50 hover:border-indigo-400 hover:bg-indigo-50/40',
                ].join(' ')}
            >
                <input
                    ref={inputRef}
                    type="file"
                    accept={accept}
                    className="hidden"
                    onChange={handleChange}
                />

                {hasError ? (
                    <div className="flex flex-col items-center gap-2 px-4 text-center">
                        <ExclamationCircleIcon className="w-8 h-8 text-red-400" />
                        <span className="text-sm font-medium text-red-600">{displayError}</span>
                        <span className="text-xs text-slate-400">Click to choose a different file</span>
                    </div>
                ) : file ? (
                    <div className="flex flex-col items-center gap-2 px-4 text-center">
                        <DocumentIcon className="w-8 h-8 text-emerald-500" />
                        <span className="text-sm font-medium text-emerald-600 break-all">{file.name}</span>
                        <span className="text-xs text-slate-400">{(file.size / 1024).toFixed(1)} KB</span>
                        <span className="text-xs text-slate-400 mt-1">Click to replace</span>
                    </div>
                ) : (
                    <div className="flex flex-col items-center gap-3 px-4 text-center">
                        <CloudArrowUpIcon className={`w-10 h-10 ${dragging ? 'text-indigo-500' : 'text-slate-400'}`} />
                        <span className="text-sm font-medium text-slate-600">{label}</span>
                        <span className="text-xs text-slate-400">{hint}</span>
                    </div>
                )}
            </div>
        </div>
    );
}
