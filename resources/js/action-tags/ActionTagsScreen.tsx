import React, { useState, useMemo } from 'react';
import { Site, PathItem, ActionItem, ActionTagItem } from './types';

interface ActionTagsScreenProps {
  sites: Site[];
  paths: PathItem[];
  actions: ActionItem[];
  tags: ActionTagItem[];
  onOpenSitesPaths: () => void;
  onCreateTag: (data: { site_id: number; path_id: number; action_key: string }) => Promise<void>;
  onDeleteTag: (tagId: number, tagKey: string) => Promise<void>;
}

export const ActionTagsScreen: React.FC<ActionTagsScreenProps> = ({
  sites,
  paths,
  actions,
  tags,
  onOpenSitesPaths,
  onCreateTag,
  onDeleteTag,
}) => {
  const [search, setSearch] = useState('');
  const [siteFilter, setSiteFilter] = useState('all');
  const [pathFilter, setPathFilter] = useState('all');
  const [actionFilter, setActionFilter] = useState('all');

  // Default standard actions fallback if table empty
  const displayActions = useMemo(() => {
    if (actions.length > 0) return actions;
    return [
      { id: 1, key: 'post', name: 'Post' },
      { id: 2, key: 'comment', name: 'Comment' },
      { id: 3, key: 'collect', name: 'Collect' },
    ];
  }, [actions]);

  // Drawer state
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [selectedSiteId, setSelectedSiteId] = useState<number | ''>(sites[0]?.id ?? '');
  const [selectedPathId, setSelectedPathId] = useState<number | ''>('');
  const [selectedActionKey, setSelectedActionKey] = useState<string>('post');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [drawerError, setDrawerError] = useState('');

  // Copy feedback state
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  // Available paths for selected site in drawer
  const drawerSitePaths = useMemo(() => {
    if (!selectedSiteId) return [];
    return paths.filter((p) => p.site_id === Number(selectedSiteId));
  }, [paths, selectedSiteId]);

  // Sync selected path when site changes in drawer
  React.useEffect(() => {
    if (drawerSitePaths.length > 0) {
      if (!drawerSitePaths.some((p) => p.id === selectedPathId)) {
        setSelectedPathId(drawerSitePaths[0].id);
      }
    } else {
      setSelectedPathId('');
    }
  }, [drawerSitePaths, selectedPathId]);

  // Preview tag key
  const previewTagKey = useMemo(() => {
    const s = sites.find((x) => x.id === Number(selectedSiteId));
    const p = paths.find((x) => x.id === Number(selectedPathId));
    if (!s || !p || !selectedActionKey) return '';
    return `${s.key}.${p.key}.${selectedActionKey}`;
  }, [sites, paths, selectedSiteId, selectedPathId, selectedActionKey]);

  // Available paths filtered by siteFilter (if chosen) for toolbar
  const toolbarPaths = useMemo(() => {
    if (siteFilter === 'all') return paths;
    const selectedSite = sites.find((s) => s.key === siteFilter);
    if (!selectedSite) return paths;
    return paths.filter((p) => p.site_id === selectedSite.id);
  }, [paths, sites, siteFilter]);

  // Check if any filter is active
  const hasActiveFilters = search !== '' || siteFilter !== 'all' || pathFilter !== 'all' || actionFilter !== 'all';

  const clearAllFilters = () => {
    setSearch('');
    setSiteFilter('all');
    setPathFilter('all');
    setActionFilter('all');
  };

  // Filtered tags list
  const filteredTags = useMemo(() => {
    return tags.filter((t) => {
      if (search) {
        const q = search.toLowerCase();
        const matches =
          t.tag_key.toLowerCase().includes(q) ||
          t.site_name.toLowerCase().includes(q) ||
          t.path_name.toLowerCase().includes(q) ||
          t.action_name.toLowerCase().includes(q);
        if (!matches) return false;
      }
      if (siteFilter !== 'all' && t.site_key !== siteFilter) return false;
      if (pathFilter !== 'all' && t.path_key !== pathFilter) return false;
      if (actionFilter !== 'all' && t.action_key !== actionFilter) return false;
      return true;
    });
  }, [tags, search, siteFilter, pathFilter, actionFilter]);

  const handleOpenDrawer = () => {
    if (!selectedSiteId && sites.length > 0) {
      setSelectedSiteId(sites[0].id);
    }
    setDrawerError('');
    setIsDrawerOpen(true);
  };

  const handleCreateTag = async () => {
    if (!selectedSiteId || !selectedPathId || !selectedActionKey) {
      setDrawerError('Vui lòng chọn đầy đủ Site, Path và Action.');
      return;
    }
    setDrawerError('');
    setIsSubmitting(true);
    try {
      await onCreateTag({
        site_id: Number(selectedSiteId),
        path_id: Number(selectedPathId),
        action_key: selectedActionKey,
      });
      setIsDrawerOpen(false);
    } catch (err: any) {
      setDrawerError(err.message || 'Lỗi khi tạo Action Tag');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleCopyTagKey = (key: string) => {
    navigator.clipboard.writeText(key);
    setCopiedKey(key);
    setTimeout(() => {
      setCopiedKey(null);
    }, 2000);
  };

  return (
    <div className="w-full flex flex-col gap-5 text-gray-900 font-sans">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-1">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-gray-900">Action Tags</h1>
          <p className="text-sm text-gray-500 mt-0.5">Danh sách Action Tags (liên kết Site, Path và Action)</p>
        </div>
        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={handleOpenDrawer}
            className="btn-primary h-9 px-3.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white text-sm font-semibold rounded-lg shadow-xs transition inline-flex items-center gap-1.5"
          >
            <span>+</span> Add Tag
          </button>
          <button
            type="button"
            onClick={onOpenSitesPaths}
            className="btn-secondary h-9 px-3.5 bg-white hover:bg-gray-50 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg shadow-xs transition inline-flex items-center gap-1.5"
          >
            Manage Sites &amp; Paths
          </button>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="flex flex-wrap items-center gap-2.5 p-2.5 bg-white rounded-xl border border-gray-200/90 shadow-xs">
        {/* Search input */}
        <div className="relative flex-1 min-w-[200px]">
          <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
          </span>
          <input
            type="text"
            placeholder="Search tags..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full h-9 pl-9 pr-3 text-sm border border-gray-200 rounded-lg bg-white placeholder:text-gray-400 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
          />
        </div>

        {/* Site dropdown */}
        <div className="w-auto min-w-[120px]">
          <select
            value={siteFilter}
            onChange={(e) => {
              setSiteFilter(e.target.value);
              setPathFilter('all');
            }}
            className="w-full h-9 px-3 text-sm border border-gray-200 rounded-lg bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer"
          >
            <option value="all">Site: All</option>
            {sites.map((s) => (
              <option key={s.id} value={s.key}>{s.name}</option>
            ))}
          </select>
        </div>

        {/* Path dropdown */}
        <div className="w-auto min-w-[120px]">
          <select
            value={pathFilter}
            onChange={(e) => setPathFilter(e.target.value)}
            className="w-full h-9 px-3 text-sm border border-gray-200 rounded-lg bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer"
          >
            <option value="all">Path: All</option>
            {toolbarPaths.map((p) => (
              <option key={p.id} value={p.key}>{p.name}</option>
            ))}
          </select>
        </div>

        {/* Action dropdown */}
        <div className="w-auto min-w-[120px]">
          <select
            value={actionFilter}
            onChange={(e) => setActionFilter(e.target.value)}
            className="w-full h-9 px-3 text-sm border border-gray-200 rounded-lg bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer"
          >
            <option value="all">Action: All</option>
            {displayActions.map((a) => (
              <option key={a.id} value={a.key}>{a.name}</option>
            ))}
          </select>
        </div>

        {/* Clear Filters CTA if active */}
        {hasActiveFilters && (
          <button
            type="button"
            onClick={clearAllFilters}
            className="h-9 px-3 text-xs font-medium text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition"
          >
            Clear Filters
          </button>
        )}
      </div>

      {/* Tags Table Area */}
      <div className="bg-white rounded-xl border border-gray-200/90 shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-sm">
            <thead>
              <tr className="border-b border-gray-200 bg-gray-50/75 text-gray-500 font-medium text-xs uppercase tracking-wider">
                <th className="py-2.5 px-4 font-semibold">Site</th>
                <th className="py-2.5 px-4 font-semibold">Path</th>
                <th className="py-2.5 px-4 font-semibold">Action</th>
                <th className="py-2.5 px-4 font-semibold">Tag Key</th>
                <th className="py-2.5 px-4 font-semibold text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {/* Scenario 1: No Sites exist in system */}
              {sites.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-12 px-4 text-center">
                    <div className="max-w-md mx-auto flex flex-col items-center gap-3">
                      <div className="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                      </div>
                      <div className="font-semibold text-gray-900">Chưa có Site / Path nào trong hệ thống</div>
                      <p className="text-xs text-gray-500 leading-relaxed">
                        Action Tag là liên kết giữa Site, Path và Action. Vui lòng tạo Website và định nghĩa URL Pattern trước khi bắt đầu.
                      </p>
                      <button
                        type="button"
                        onClick={onOpenSitesPaths}
                        className="mt-1 h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-xs transition"
                      >
                        Manage Sites &amp; Paths
                      </button>
                    </div>
                  </td>
                </tr>
              ) : tags.length === 0 ? (
                /* Scenario 2: Sites exist, but no Action Tags created yet */
                <tr>
                  <td colSpan={5} className="py-12 px-4 text-center">
                    <div className="max-w-md mx-auto flex flex-col items-center gap-3">
                      <div className="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center text-gray-500">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                      </div>
                      <div className="font-semibold text-gray-900">Chưa có Action Tag nào</div>
                      <p className="text-xs text-gray-500 leading-relaxed">
                        Tạo Action Tag đầu tiên. Hệ thống sẽ tự động tạo Script Request để kỹ thuật viên RPA nhận việc.
                      </p>
                      <button
                        type="button"
                        onClick={handleOpenDrawer}
                        className="mt-1 h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-xs transition"
                      >
                        + Add First Tag
                      </button>
                    </div>
                  </td>
                </tr>
              ) : filteredTags.length === 0 ? (
                /* Scenario 3: Filters active, no matching rows */
                <tr>
                  <td colSpan={5} className="py-10 px-4 text-center">
                    <div className="max-w-md mx-auto flex flex-col items-center gap-2.5">
                      <p className="text-sm font-medium text-gray-700">Không tìm thấy Action Tag nào phù hợp</p>
                      <p className="text-xs text-gray-500">Thử thay đổi từ khóa tìm kiếm hoặc các dropdown bộ lọc.</p>
                      <button
                        type="button"
                        onClick={clearAllFilters}
                        className="mt-1 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:text-emerald-800 bg-emerald-50 hover:bg-emerald-100 rounded-lg transition"
                      >
                        Clear Filters
                      </button>
                    </div>
                  </td>
                </tr>
              ) : (
                /* Populated rows */
                filteredTags.map((tag) => {
                  const isCopied = copiedKey === tag.tag_key;
                  return (
                    <tr key={tag.id} className="hover:bg-gray-50/60 transition-colors">
                      <td className="py-2.5 px-4 font-semibold text-gray-900">{tag.site_name}</td>
                      <td className="py-2.5 px-4 text-gray-700">{tag.path_name}</td>
                      <td className="py-2.5 px-4 text-gray-700">{tag.action_name}</td>
                      <td className="py-2.5 px-4 font-mono text-xs text-gray-700">
                        <span className="bg-gray-100 px-2 py-1 rounded text-gray-800">
                          {tag.tag_key}
                        </span>
                      </td>
                      <td className="py-2.5 px-4 text-right">
                        <div className="inline-flex items-center gap-1.5 justify-end">
                          <button
                            type="button"
                            onClick={() => handleCopyTagKey(tag.tag_key)}
                            title="Copy Tag Key"
                            className="h-7 px-2.5 text-xs font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 border border-gray-200 rounded-lg transition inline-flex items-center gap-1"
                          >
                            <span>{isCopied ? '✓ Copied' : 'Copy Key'}</span>
                          </button>
                          <button
                            type="button"
                            onClick={() => onDeleteTag(tag.id, tag.tag_key)}
                            title="Xóa Action Tag"
                            className="h-7 px-2 text-xs font-medium text-red-600 hover:text-red-700 hover:bg-red-50 border border-red-200 rounded-lg transition inline-flex items-center"
                          >
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Add Action Tag Drawer (Slide-over) */}
      {isDrawerOpen && (
        <div className="fixed inset-0 z-50 overflow-hidden flex justify-end">
          <div
            className="fixed inset-0 bg-black/30 backdrop-blur-xs transition-opacity"
            onClick={() => setIsDrawerOpen(false)}
          />
          <div className="relative w-full max-w-md bg-white shadow-2xl flex flex-col z-10 animate-in slide-in-from-right duration-200">
            {/* Drawer Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <div>
                <h2 className="text-lg font-bold text-gray-900">Add Action Tag</h2>
                <p className="text-xs text-gray-400 mt-0.5">Liên kết Site, Path và Action (tự động tạo Script Request)</p>
              </div>
              <button
                type="button"
                onClick={() => setIsDrawerOpen(false)}
                className="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Drawer Body */}
            <div className="flex-1 overflow-y-auto px-6 py-5 flex flex-col gap-4 text-sm">
              {drawerError && (
                <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs">
                  {drawerError}
                </div>
              )}

              {/* Site Select */}
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1.5">Site</label>
                <select
                  value={selectedSiteId}
                  onChange={(e) => setSelectedSiteId(Number(e.target.value))}
                  className="w-full h-9 px-3 border border-gray-200 rounded-lg bg-white text-gray-900 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                >
                  {sites.length === 0 ? (
                    <option value="">(Chưa có Site nào)</option>
                  ) : (
                    sites.map((s) => (
                      <option key={s.id} value={s.id}>{s.name}</option>
                    ))
                  )}
                </select>
                {sites.length === 0 && (
                  <div className="mt-2 p-2.5 bg-amber-50 border border-amber-200/80 rounded-lg text-xs text-amber-800 flex items-center justify-between">
                    <span>Chưa có website nào trong hệ thống.</span>
                    <button
                      type="button"
                      onClick={() => {
                        setIsDrawerOpen(false);
                        onOpenSitesPaths();
                      }}
                      className="font-semibold underline hover:text-amber-900 ml-2"
                    >
                      Tạo Site &rarr;
                    </button>
                  </div>
                )}
              </div>

              {/* Path Select */}
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1.5">Path</label>
                <select
                  value={selectedPathId}
                  onChange={(e) => setSelectedPathId(Number(e.target.value))}
                  className="w-full h-9 px-3 border border-gray-200 rounded-lg bg-white text-gray-900 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                >
                  {drawerSitePaths.length === 0 ? (
                    <option value="">(Chưa có Path nào cho Site này)</option>
                  ) : (
                    drawerSitePaths.map((p) => (
                      <option key={p.id} value={p.id}>{p.name} ({p.url_pattern})</option>
                    ))
                  )}
                </select>
                {drawerSitePaths.length === 0 && (
                  <p className="text-[11px] text-amber-600 mt-1">
                    Site này chưa có Path. Vui lòng tạo Path trong Manage Sites &amp; Paths trước.
                  </p>
                )}
              </div>

              {/* Available Actions (Radio Cards) */}
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-2">Available Actions</label>
                <div className="flex flex-col gap-2">
                  {displayActions.map((act) => {
                    const isSelected = selectedActionKey === act.key;
                    return (
                      <div
                        key={act.key}
                        onClick={() => setSelectedActionKey(act.key)}
                        className={`flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition select-none ${
                          isSelected
                            ? 'border-emerald-500 bg-emerald-50/50 text-emerald-900 font-medium shadow-xs'
                            : 'border-gray-200 hover:border-gray-300 bg-white text-gray-700'
                        }`}
                      >
                        <div
                          className={`w-5 h-5 rounded-full border flex items-center justify-center transition ${
                            isSelected
                              ? 'border-emerald-600 bg-emerald-600 text-white'
                              : 'border-gray-300 bg-white'
                          }`}
                        >
                          {isSelected && (
                            <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M5 13l4 4L19 7" />
                            </svg>
                          )}
                        </div>
                        <span className="capitalize text-sm">{act.name}</span>
                      </div>
                    );
                  })}
                </div>
              </div>

              {/* Tag Key Preview */}
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1.5">Tag Key Preview</label>
                <input
                  type="text"
                  disabled
                  value={previewTagKey}
                  placeholder="site.path.action"
                  className="w-full h-9 px-3 bg-gray-50 text-gray-600 font-mono text-xs border border-gray-200 rounded-lg cursor-not-allowed select-all"
                />
              </div>

              {/* Notice: Auto Script Request */}
              <div className="p-3 bg-emerald-50/60 border border-emerald-200/80 rounded-xl text-xs text-emerald-800 leading-relaxed flex items-start gap-2.5">
                <svg className="w-4 h-4 text-emerald-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>
                  <strong>Tự động tạo Script Request:</strong> Sau khi tạo Tag, hệ thống sẽ tự động kích hoạt Script Request (loại create) để kỹ thuật viên RPA nhận việc và xây dựng Script tương ứng.
                </span>
              </div>
            </div>

            {/* Drawer Footer */}
            <div className="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-gray-100 bg-gray-50/50">
              <button
                type="button"
                onClick={() => setIsDrawerOpen(false)}
                className="btn-secondary h-9 px-3.5 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-white transition"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={isSubmitting || !previewTagKey || drawerSitePaths.length === 0}
                onClick={handleCreateTag}
                className="btn-primary h-9 px-4 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg shadow-xs transition"
              >
                {isSubmitting ? 'Creating...' : 'Create Tag'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
