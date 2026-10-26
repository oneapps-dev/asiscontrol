import React from 'react';
import { api } from '../api';
import { Icon } from './Icon';

export interface EvidenceFile {
  id: string;
  file: File;
  previewUrl: string;
  isImage: boolean;
}

export function createEvidenceFiles(fileList: FileList | File[]): EvidenceFile[] {
  return Array.from(fileList).map((file) => ({
    id: `evi-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    file,
    previewUrl: URL.createObjectURL(file),
    isImage: file.type.startsWith('image/'),
  }));
}

export function revokeEvidenceFiles(files: EvidenceFile[]): void {
  files.forEach((item) => URL.revokeObjectURL(item.previewUrl));
}

export function clearEvidenceFiles(files: EvidenceFile[], onChange: (files: EvidenceFile[]) => void): void {
  revokeEvidenceFiles(files);
  onChange([]);
}

export async function uploadEvidenceFiles(ownerType: string, ownerId: string, files: EvidenceFile[]): Promise<void> {
  for (const item of files) {
    try {
      await api.uploadFile({ ownerType, ownerId, file: item.file });
    } catch (error) {
      // Evidences are immutable; an individual upload failure must not hide
      // the parent operation, which can be retried from the record detail.
      console.warn('No fue posible subir una evidencia', { ownerType, ownerId, file: item.file.name, error });
    }
  }
}

export function EvidencePicker({
  files,
  onChange,
  label = 'Evidencias',
}: {
  files: EvidenceFile[];
  onChange: (files: EvidenceFile[]) => void;
  label?: string;
}) {
  const addFiles = (fileList?: FileList | null) => {
    if (!fileList?.length) return;
    onChange([...files, ...createEvidenceFiles(fileList)]);
  };

  const removeFile = (id: string) => {
    const target = files.find((item) => item.id === id);
    if (target) URL.revokeObjectURL(target.previewUrl);
    onChange(files.filter((item) => item.id !== id));
  };

  return (
    <div className="grid gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="label mb-0">{label}</p>
          <p className="text-xs text-on-surface-variant">Agrega una o varias fotos o PDFs. Las imágenes se guardan como WebP en el servidor.</p>
        </div>
        <label className="btn-ghost cursor-pointer">
          <Icon name="add_photo_alternate" /> Agregar evidencia
          <input className="sr-only" type="file" accept="image/*,application/pdf" capture="environment" multiple onChange={(event) => { addFiles(event.target.files); event.currentTarget.value = ''; }} />
        </label>
      </div>
      {files.length > 0 ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {files.map((item) => (
            <div key={item.id} className="relative overflow-hidden rounded-xl border border-border bg-surface-container-low">
              <div className="grid aspect-[4/3] place-items-center bg-surface-container">
                {item.isImage ? <img src={item.previewUrl} alt={item.file.name} className="h-full w-full object-cover" /> : <div className="grid place-items-center gap-2 text-on-surface-variant"><Icon name="picture_as_pdf" className="text-4xl text-danger" /><span className="text-xs font-semibold">PDF</span></div>}
              </div>
              <div className="flex items-center justify-between gap-2 p-2"><span className="truncate text-xs font-medium text-text-primary" title={item.file.name}>{item.file.name}</span><button type="button" className="rounded-full p-1 text-danger hover:bg-danger/10" onClick={() => removeFile(item.id)} aria-label={`Eliminar ${item.file.name}`}><Icon name="delete" /></button></div>
            </div>
          ))}
        </div>
      ) : <div className="rounded-xl border border-dashed border-border bg-surface-container-low p-4 text-sm text-on-surface-variant">Sin evidencias agregadas todavía.</div>}
    </div>
  );
}
