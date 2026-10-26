import React, { useState, useMemo, useEffect } from 'react';
import { Site, PathItem } from './types';
import { parseInputsFromPattern, resolveUrl } from './utils';

interface SitesPathsScreenProps {
  sites: Site[];
  paths: PathItem[];
  onBackToTags: () => void;
  onSaveSite: (siteData: {
    id?: number;
    name: string;
    base_url?: string;
    base_url_mode?: 'fixed' | 'dynamic';
    test_base_url?: string;
    description?: string;
  }) => Promise<void>;
  onSavePath: (pathData: {
    id?: number;
    site_id: number;
    name: string;
    url_pattern: string;
    description?: string;
  }) => Promise<void>;
}

export const SitesPathsScreen: React.FC<SitesPathsScreenProps> = ({
  sites,
  paths,
  onBackToTags,
  onSaveSite,
  onSavePath,
}) => {
  const [selectedSiteId, setSelectedSiteId] = useState<number>(sites[0]?.id ?? 0);
  const [siteSearch, setSiteSearch] = useState('');

  // Selected site object
  const activeSite = useMemo(() => {
    return sites.find((s) => s.id === selectedSiteId) || sites[0] || null;
  }, [sites, selectedSiteId]);

  const isDynamicSite = activeSite?.base_url_mode === 'dynamic';

  // Paths of the active site
  const activeSitePaths = useMemo(() => {
    if (!activeSite) return [];
    return paths.filter((p) => p.site_id === activeSite.id);
  }, [paths, activeSite]);

  // Selected path in the right side
  const [selectedPathId, setSelectedPathId] = useState<number | 'new' | null>(null);

  // Form states for Edit / Add Path
  const [pathNameInput, setPathNameInput] = useState('');
  const [pathPatternInput, setPathPatternInput] = useState('');
  const [testValues, setTestValues] = useState<Record<string, string>>({});
  const [isSavingPath, setIsSavingPath] = useState(false);
  const [pathError, setPathError] = useState('');
  const [copiedUrl, setCopiedUrl] = useState(false);

  // Dynamic Test Base URL (remembered per site in localStorage)
  const [currentTestBaseUrl, setCurrentTestBaseUrl] = useState<string>('');

  useEffect(() => {
    if (!activeSite) {
      setCurrentTestBaseUrl('');
      return;
    }
    if (activeSite.base_url_mode === 'dynamic') {
      const stored = localStorage.getItem(`sa_test_base_url_${activeSite.id}`);
      setCurrentTestBaseUrl(stored ?? (activeSite.test_base_url || 'https://example.test/blog/'));
    } else {
      setCurrentTestBaseUrl(activeSite.base_url || '');
    }
  }, [activeSite?.id, activeSite?.base_url_mode, activeSite?.test_base_url, activeSite?.base_url]);

  const handleTestBaseUrlChange = (val: string) => {
    setCurrentTestBaseUrl(val);
    if (activeSite && activeSite.base_url_mode === 'dynamic') {
      localStorage.setItem(`sa_test_base_url_${activeSite.id}`, val);
    }
  };

  // Site Modal State (Add or Edit Site)
  const [siteModalOpen, setSiteModalOpen] = useState(false);
  const [editingSite, setEditingSite] = useState<Site | null>(null);
  const [siteBaseUrlMode, setSiteBaseUrlMode] = useState<'fixed' | 'dynamic'>('fixed');
  const [siteNameInput, setSiteNameInput] = useState('');
  const [siteBaseUrlInput, setSiteBaseUrlInput] = useState('');
  const [siteTestBaseUrlInput, setSiteTestBaseUrlInput] = useState('');
  const [siteDescInput, setSiteDescInput] = useState('');
  const [isSavingSite, setIsSavingSite] = useState(false);
  const [siteError, setSiteError] = useState('');

  // Auto select first path when active site changes
  useEffect(() => {
    if (activeSitePaths.length > 0) {
      const current = activeSitePaths.find((p) => p.id === selectedPathId);
      if (!current && selectedPathId !== 'new') {
        selectPath(activeSitePaths[0]);
      }
    } else {
      setSelectedPathId('new');
      setPathNameInput('');
      setPathPatternInput('/');
      setTestValues({});
    }
  }, [activeSitePaths]);

  const selectPath = (p: PathItem) => {
    setSelectedPathId(p.id);
    setPathNameInput(p.name);
    setPathPatternInput(p.url_pattern);
    setPathError('');

    // Prepopulate test values
    const initialTests: Record<string, string> = {};
    if (p.path_inputs) {
      for (const input of p.path_inputs) {
        if (input.key === 'group_id') initialTests[input.key] = '123456789';
        else if (input.key === 'post_id') initialTests[input.key] = '987654321';
        else if (input.key === 'username') initialTests[input.key] = 'demo_user';
        else initialTests[input.key] = 'sample_value';
      }
    }
    setTestValues(initialTests);
  };

  const startAddPath = () => {
    if (!activeSite) {
      openAddSiteModal();
      return;
    }
    setSelectedPathId('new');
    setPathNameInput('');
    setPathPatternInput('/wp-admin/post-new.php');
    setPathError('');
    setTestValues({});
  };

  // Detected inputs based on pattern input
  const detectedInputs = useMemo(() => {
    return parseInputsFromPattern(pathPatternInput);
  }, [pathPatternInput]);

  // Resolved URL based on active site base_url (or currentTestBaseUrl for dynamic) and test inputs
  const resolvedPreview = useMemo(() => {
    if (!activeSite) return { url: '', missing: ['base_url'], valid: false, error: 'Chưa chọn Site.' };
    const effectiveBase = isDynamicSite ? currentTestBaseUrl : activeSite.base_url;
    return resolveUrl(effectiveBase, pathPatternInput, testValues);
  }, [activeSite, isDynamicSite, currentTestBaseUrl, pathPatternInput, testValues]);

  // Handle Save Path
  const handleSavePath = async () => {
    if (!activeSite) return;
    if (!pathNameInput.trim() || !pathPatternInput.trim()) {
      setPathError('Vui lòng điền đầy đủ Tên Path và URL Pattern.');
      return;
    }
    setPathError('');
    setIsSavingPath(true);
    try {
      await onSavePath({
        id: selectedPathId === 'new' ? undefined : (selectedPathId ?? undefined),
        site_id: activeSite.id,
        name: pathNameInput.trim(),
        url_pattern: pathPatternInput.trim(),
      });
    } catch (err: any) {
      setPathError(err.message || 'Lỗi khi lưu Path');
    } finally {
      setIsSavingPath(false);
    }
  };

  // Handle Open Site Modal
  const openAddSiteModal = () => {
    setEditingSite(null);
    setSiteBaseUrlMode('fixed');
    setSiteNameInput('');
    setSiteBaseUrlInput('https://');
    setSiteTestBaseUrlInput('');
    setSiteDescInput('');
    setSiteError('');
    setSiteModalOpen(true);
  };

  const openEditSiteModal = (site: Site) => {
    setEditingSite(site);
    setSiteBaseUrlMode(site.base_url_mode ?? 'fixed');
    setSiteNameInput(site.name);
    setSiteBaseUrlInput(site.base_url || '');
    setSiteTestBaseUrlInput(site.test_base_url || '');
    setSiteDescInput(site.description || '');
    setSiteError('');
    setSiteModalOpen(true);
  };

  // Handle Save Site
  const handleSaveSite = async () => {
    if (!siteNameInput.trim()) {
      setSiteError('Vui lòng nhập Tên Site.');
      return;
    }
    if (siteBaseUrlMode === 'fixed' && !siteBaseUrlInput.trim()) {
      setSiteError('Vui lòng nhập Base URL hợp lệ cho Site cố định.');
      return;
    }
    setSiteError('');
    setIsSavingSite(true);
    try {
      await onSaveSite({
        id: editingSite?.id,
        name: siteNameInput.trim(),
        base_url: siteBaseUrlMode === 'fixed' ? siteBaseUrlInput.trim() : '',
        base_url_mode: siteBaseUrlMode,
        test_base_url: siteTestBaseUrlInput.trim() || undefined,
        description: siteDescInput.trim() || undefined,
      });
      setSiteModalOpen(false);
    } catch (err: any) {
      setSiteError(err.message || 'Lỗi khi lưu Site');
    } finally {
      setIsSavingSite(false);
    }
  };

  // Filtered sites for left column
  const filteredSites = useMemo(() => {
    if (!siteSearch) return sites;
    const q = siteSearch.toLowerCase();
    return sites.filter((s) => {
      const matchName = s.name.toLowerCase().includes(q);
      const matchUrl = (s.base_url || '').toLowerCase().includes(q);
      const matchTest = (s.test_base_url || '').toLowerCase().includes(q);
      return matchName || matchUrl || matchTest;
    });
  }, [sites, siteSearch]);

  const copyResolvedUrl = () => {
    if (!resolvedPreview.url) return;
    navigator.clipboard.writeText(resolvedPreview.url);
    setCopiedUrl(true);
    setTimeout(() => setCopiedUrl(false), 2000);
  };

  // Site icon helper
  const renderSiteIcon = (siteKey: string) => {
    const k = siteKey.toLowerCase();
    if (k.includes('facebook')) {
      return (
        <div className="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-base flex-shrink-0 shadow-xs">
          f
        </div>
      );
    }
    if (k.includes('twitter') || k === 'x') {
      return (
        <div className="w-8 h-8 rounded-lg bg-black flex items-center justify-center text-white font-bold text-xs flex-shrink-0 shadow-xs">
          X
        </div>
      );
    }
    if (k.includes('reddit')) {
      return (
        <div className="w-8 h-8 rounded-lg bg-orange-600 flex items-center justify-center text-white font-bold text-xs flex-shrink-0 shadow-xs">
          r/
        </div>
      );
    }
    if (k.includes('wordpress') || k === 'wp') {
      return (
        <div className="w-8 h-8 rounded-lg bg-[#21759b] flex items-center justify-center text-white font-serif font-bold text-base flex-shrink-0 shadow-xs">
          W
        </div>
      );
    }
    return (
      <div className="w-8 h-8 rounded-lg bg-gray-700 flex items-center justify-center text-white font-bold text-xs flex-shrink-0 shadow-xs">
        {siteKey.substring(0, 2).toUpperCase()}
      </div>
    );
  };

  return (
    <div className="w-full flex flex-col gap-5 text-gray-900 font-sans">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-1">
        <div>
          <button
            type="button"
            onClick={onBackToTags}
            className="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 hover:text-emerald-800 mb-1 transition"
          >
            <span>&larr;</span> Action Tags
          </button>
          <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-gray-900">Sites &amp; Paths</h1>
          <p className="text-sm text-gray-500 mt-0.5">Quản lý Base URL, URL Patterns và Path Inputs</p>
        </div>
        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={openAddSiteModal}
            className="btn-secondary h-9 px-3.5 bg-white hover:bg-gray-50 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg shadow-xs transition inline-flex items-center gap-1.5"
          >
            Add Site
          </button>
          <button
            type="button"
            onClick={startAddPath}
            className="btn-primary h-9 px-3.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white text-sm font-semibold rounded-lg shadow-xs transition inline-flex items-center gap-1.5"
          >
            Add Path
          </button>
        </div>
      </div>

      {/* Main 2-Column Layout */}
      <div className="sites-paths-grid">
        {/* Left Column: Sites (~300px) */}
        <div className="sites-paths-sidebar bg-white rounded-xl border border-gray-200/90 shadow-xs p-4 flex flex-col gap-3.5">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-bold text-gray-900 uppercase tracking-wide">Sites</h2>
            <span className="text-xs text-gray-400 font-medium">{sites.length} site(s)</span>
          </div>

          {/* Search sites */}
          <div className="relative">
            <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </span>
            <input
              type="text"
              placeholder="Search sites..."
              value={siteSearch}
              onChange={(e) => setSiteSearch(e.target.value)}
              className="w-full h-9 pl-9 pr-3 text-sm border border-gray-200 rounded-lg bg-white placeholder:text-gray-400 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
            />
          </div>

          {/* Sites List */}
          <div className="flex flex-col gap-2 max-h-[580px] overflow-y-auto pr-0.5">
            {sites.length === 0 ? (
              <div className="py-8 text-center px-2">
                <p className="text-xs text-gray-500 mb-2">Chưa có website nào.</p>
                <button
                  type="button"
                  onClick={openAddSiteModal}
                  className="h-8 px-3 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition"
                >
                  + Add Site
                </button>
              </div>
            ) : filteredSites.length === 0 ? (
              <div className="py-6 text-center text-xs text-gray-400">
                Không tìm thấy website phù hợp.
              </div>
            ) : (
              filteredSites.map((site) => {
                const isActive = activeSite?.id === site.id;
                const isDynamic = site.base_url_mode === 'dynamic';
                return (
                  <div
                    key={site.id}
                    onClick={() => setSelectedSiteId(site.id)}
                    className={`flex items-center justify-between p-2.5 rounded-xl border cursor-pointer transition select-none ${
                      isActive
                        ? 'border-emerald-500 bg-emerald-50/40 shadow-xs border-l-4'
                        : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50/60 bg-white'
                    }`}
                  >
                    <div className="flex items-center gap-2.5 overflow-hidden min-w-0">
                      {renderSiteIcon(site.key)}
                      <div className="overflow-hidden min-w-0">
                        <div className="flex items-center gap-1.5">
                          <span className="font-semibold text-sm text-gray-900 truncate">{site.name}</span>
                          {isDynamic && (
                            <span className="px-1.5 py-0.2 rounded text-[10px] font-semibold bg-sky-100 text-sky-700 border border-sky-200 flex-shrink-0">
                              Dynamic
                            </span>
                          )}
                        </div>
                        <div className="text-[11px] text-gray-400 truncate font-mono">
                          {isDynamic
                            ? (site.test_base_url ? `Test: ${site.test_base_url}` : 'Dynamic Base URL')
                            : site.base_url}
                        </div>
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        openEditSiteModal(site);
                      }}
                      className="p-1.5 text-gray-400 hover:text-gray-700 rounded-lg hover:bg-gray-100 transition flex-shrink-0"
                      title="Chỉnh sửa Site"
                    >
                      <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                      </svg>
                    </button>
                  </div>
                );
              })
            )}
          </div>
        </div>

        {/* Right Column: Paths List & Path Editor */}
        <div className="sites-paths-main flex flex-col gap-5">
          {/* Paths Table Card */}
          <div className="bg-white rounded-xl border border-gray-200/90 shadow-xs overflow-hidden">
            <div className="px-4 py-3 border-b border-gray-200 flex items-center justify-between bg-gray-50/50">
              <div className="flex items-center gap-2">
                <h2 className="text-sm font-bold text-gray-900">
                  Paths &middot; <span className="text-emerald-700">{activeSite?.name || 'Chưa chọn'}</span>
                </h2>
                {isDynamicSite && (
                  <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-sky-100 text-sky-800 border border-sky-200">
                    Dynamic CMS
                  </span>
                )}
              </div>
              <span className="text-xs text-gray-400 font-medium">{activeSitePaths.length} path(s)</span>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-sm">
                <thead>
                  <tr className="border-b border-gray-200 bg-gray-50/25 text-gray-500 font-medium text-xs uppercase tracking-wider">
                    <th className="py-2.5 px-4 font-semibold">Path Name</th>
                    <th className="py-2.5 px-4 font-semibold">URL Pattern</th>
                    <th className="py-2.5 px-4 text-right font-semibold">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {activeSitePaths.length === 0 ? (
                    <tr>
                      <td colSpan={3} className="py-8 px-4 text-center">
                        <p className="text-xs text-gray-500 mb-2">Site này chưa có URL Path nào.</p>
                        <button
                          type="button"
                          onClick={startAddPath}
                          className="h-8 px-3 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition"
                        >
                          + Add Path
                        </button>
                      </td>
                    </tr>
                  ) : (
                    activeSitePaths.map((path) => {
                      const isSelected = selectedPathId === path.id;
                      return (
                        <tr
                          key={path.id}
                          onClick={() => selectPath(path)}
                          className={`cursor-pointer transition-colors ${
                            isSelected ? 'bg-emerald-50/40 font-medium' : 'hover:bg-gray-50/60'
                          }`}
                        >
                          <td className="py-2.5 px-4 text-gray-900">{path.name}</td>
                          <td className="py-2.5 px-4 font-mono text-xs text-gray-600">{path.url_pattern}</td>
                          <td className="py-2.5 px-4 text-right">
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                selectPath(path);
                              }}
                              className="p-1.5 text-gray-400 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition"
                              title="Sửa Path"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                              </svg>
                            </button>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Path Editor Card (Edit Path or Add Path) */}
          <div className="bg-white rounded-xl border border-gray-200/90 shadow-xs p-5 flex flex-col gap-4.5">
            <div className="flex items-center justify-between pb-2 border-b border-gray-100">
              <h3 className="text-sm font-bold text-gray-900">
                {selectedPathId === 'new' ? 'Add Path' : 'Edit Path'} &middot;{' '}
                <span className="text-emerald-700">{pathNameInput || 'New Path'}</span>
              </h3>
            </div>

            {pathError && (
              <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs">
                {pathError}
              </div>
            )}

            {/* Inputs: Path Name & URL Pattern */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3.5">
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1.5">Path Name</label>
                <input
                  type="text"
                  placeholder="e.g. New Post, Edit Post, Settings"
                  value={pathNameInput}
                  onChange={(e) => setPathNameInput(e.target.value)}
                  className="w-full h-9 px-3 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1.5">URL Pattern</label>
                <input
                  type="text"
                  placeholder="e.g. /wp-admin/post-new.php"
                  value={pathPatternInput}
                  onChange={(e) => setPathPatternInput(e.target.value)}
                  className="w-full h-9 px-3 text-sm font-mono border border-gray-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                />
              </div>
            </div>

            {/* Dynamic Site: Test Base URL Input */}
            {isDynamicSite && (
              <div className="p-3.5 bg-sky-50/70 border border-sky-200/80 rounded-xl flex flex-col gap-2">
                <div className="flex items-center justify-between">
                  <label className="text-xs font-bold text-sky-900 flex items-center gap-1.5">
                    <span className="px-1.5 py-0.5 rounded text-[10px] uppercase font-semibold bg-sky-200/80 text-sky-800">
                      Dynamic Base URL
                    </span>
                    <span>Test Base URL</span>
                  </label>
                  <span className="text-[11px] text-sky-600 font-medium">Được ghi nhớ tự động cho site này</span>
                </div>
                <input
                  type="text"
                  value={currentTestBaseUrl}
                  onChange={(e) => handleTestBaseUrlChange(e.target.value)}
                  placeholder="https://example.test/blog/"
                  className="w-full h-8 px-2.5 text-xs font-mono bg-white border border-sky-300 rounded-md text-gray-900 focus:outline-none focus:ring-1 focus:ring-sky-500"
                />
                <p className="text-[11px] text-sky-700">
                  🔒 Test Base URL hỗ trợ cả thư mục con (ví dụ <code>https://example.test/blog/</code>). Tuyệt đối KHÔNG tự dùng làm URL chạy production.
                </p>
              </div>
            )}

            {/* Detected Path Inputs */}
            <div>
              <h4 className="text-xs font-semibold text-gray-800 mb-1.5">Detected Path Inputs</h4>
              <div className="border border-gray-200 rounded-lg overflow-hidden">
                <table className="w-full text-left text-xs">
                  <thead className="bg-gray-50/75 text-gray-500 border-b border-gray-200">
                    <tr>
                      <th className="py-2 px-3 font-semibold">Input Name</th>
                      <th className="py-2 px-3 font-semibold">Type</th>
                      <th className="py-2 px-3 font-semibold">Required</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {detectedInputs.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="py-2.5 px-3 text-center text-gray-400">
                          Không có tham số biến trong URL Pattern.
                        </td>
                      </tr>
                    ) : (
                      detectedInputs.map((inp) => (
                        <tr key={inp.key}>
                          <td className="py-2 px-3 font-mono font-medium text-gray-800">{inp.key}</td>
                          <td className="py-2 px-3 text-gray-600">{inp.type}</td>
                          <td className="py-2 px-3">
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                              Required
                            </span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Test Path Input Values */}
            {detectedInputs.length > 0 && (
              <div>
                <h4 className="text-xs font-semibold text-gray-800 mb-1.5">Test Path Input Values</h4>
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5">
                  {detectedInputs.map((inp) => (
                    <div key={inp.key} className="flex items-center gap-2">
                      <span className="font-mono text-xs text-gray-600 w-24 truncate">{inp.key}</span>
                      <input
                        type="text"
                        value={testValues[inp.key] ?? ''}
                        onChange={(e) => setTestValues({ ...testValues, [inp.key]: e.target.value })}
                        placeholder="Test value..."
                        className="flex-1 h-8 px-2.5 text-xs font-mono border border-gray-200 rounded-md focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                      />
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Resolved URL Preview */}
            <div>
              <h4 className="text-xs font-semibold text-gray-800 mb-1.5">Resolved URL</h4>
              <div className="flex items-center gap-2">
                <input
                  type="text"
                  readOnly
                  value={resolvedPreview.url}
                  placeholder={isDynamicSite && !currentTestBaseUrl.trim() ? 'Vui lòng nhập Test Base URL ở trên để xem preview...' : ''}
                  className="flex-1 h-9 px-3 text-xs font-mono bg-gray-50 border border-gray-200 rounded-lg text-gray-800 focus:outline-none select-all"
                />
                <button
                  type="button"
                  onClick={copyResolvedUrl}
                  disabled={!resolvedPreview.url}
                  className="h-9 px-3 border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-40 text-gray-600 text-xs font-medium transition inline-flex items-center gap-1.5 flex-shrink-0"
                  title="Copy URL"
                >
                  <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                  <span>{copiedUrl ? 'Copied!' : 'Copy'}</span>
                </button>
              </div>
              {resolvedPreview.error && (
                <p className="text-[11px] text-amber-600 mt-1">
                  {resolvedPreview.error}
                </p>
              )}
            </div>

            {/* Bottom Note & Actions */}
            <div className="pt-2 flex flex-wrap items-center justify-between gap-4 border-t border-gray-100">
              <p className="text-xs text-gray-400 flex items-center gap-1">
                <svg className="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>Path Inputs are inherited by Script Requests</span>
              </p>
              <div className="flex items-center gap-2.5">
                <button
                  type="button"
                  onClick={() => {
                    if (activeSitePaths.length > 0) selectPath(activeSitePaths[0]);
                  }}
                  className="btn-secondary h-9 px-3.5 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-white transition"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={isSavingPath || !pathNameInput.trim() || !pathPatternInput.trim()}
                  onClick={handleSavePath}
                  className="btn-primary h-9 px-4 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg shadow-xs transition"
                >
                  {isSavingPath ? 'Saving...' : 'Save Path'}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Add / Edit Site Modal */}
      {siteModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-xs">
          <div className="w-full max-w-md bg-white rounded-2xl shadow-xl border border-gray-100 p-6 flex flex-col gap-4 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-bold text-gray-900">
                {editingSite ? 'Edit Site' : 'Add Site'}
              </h3>
              <button
                type="button"
                onClick={() => setSiteModalOpen(false)}
                className="text-gray-400 hover:text-gray-600 p-1 rounded-lg"
              >
                &times;
              </button>
            </div>

            {siteError && (
              <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs">
                {siteError}
              </div>
            )}

            {/* Base URL Mode Selector */}
            <div>
              <label className="block text-xs font-semibold text-gray-700 mb-1.5">Base URL Mode</label>
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={() => setSiteBaseUrlMode('fixed')}
                  className={`py-2 px-3 text-xs font-medium rounded-lg border text-left transition flex flex-col gap-0.5 ${
                    siteBaseUrlMode === 'fixed'
                      ? 'border-emerald-500 bg-emerald-50/60 text-emerald-900 font-semibold ring-1 ring-emerald-500'
                      : 'border-gray-200 hover:border-gray-300 text-gray-700 bg-white'
                  }`}
                >
                  <span className="flex items-center gap-1.5">
                    <span className={`w-2 h-2 rounded-full ${siteBaseUrlMode === 'fixed' ? 'bg-emerald-600' : 'bg-gray-300'}`} />
                    Fixed (Cố định)
                  </span>
                  <span className="text-[10px] text-gray-500">Facebook, Reddit, X...</span>
                </button>
                <button
                  type="button"
                  onClick={() => setSiteBaseUrlMode('dynamic')}
                  className={`py-2 px-3 text-xs font-medium rounded-lg border text-left transition flex flex-col gap-0.5 ${
                    siteBaseUrlMode === 'dynamic'
                      ? 'border-emerald-500 bg-emerald-50/60 text-emerald-900 font-semibold ring-1 ring-emerald-500'
                      : 'border-gray-200 hover:border-gray-300 text-gray-700 bg-white'
                  }`}
                >
                  <span className="flex items-center gap-1.5">
                    <span className={`w-2 h-2 rounded-full ${siteBaseUrlMode === 'dynamic' ? 'bg-emerald-600' : 'bg-gray-300'}`} />
                    Dynamic (CMS / Động)
                  </span>
                  <span className="text-[10px] text-gray-500">WordPress, domain động</span>
                </button>
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-gray-700 mb-1.5">
                Site Name <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                value={siteNameInput}
                onChange={(e) => setSiteNameInput(e.target.value)}
                placeholder="e.g. WordPress, Facebook, Reddit"
                className="w-full h-9 px-3 text-sm border border-gray-200 rounded-lg focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
              />
            </div>

            {siteBaseUrlMode === 'fixed' ? (
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1.5">
                  Base URL <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  value={siteBaseUrlInput}
                  onChange={(e) => setSiteBaseUrlInput(e.target.value)}
                  placeholder="https://www.facebook.com"
                  className="w-full h-9 px-3 text-sm font-mono border border-gray-200 rounded-lg focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                />
                <p className="text-[11px] text-gray-400 mt-1">
                  URL cố định cho platform (ví dụ: https://www.facebook.com).
                </p>
              </div>
            ) : (
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1.5">
                  Test Base URL <span className="text-gray-400 font-normal">(Dùng preview/test)</span>
                </label>
                <input
                  type="text"
                  value={siteTestBaseUrlInput}
                  onChange={(e) => setSiteTestBaseUrlInput(e.target.value)}
                  placeholder="https://example.test/blog/"
                  className="w-full h-9 px-3 text-sm font-mono border border-gray-200 rounded-lg focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                />
                <p className="text-[11px] text-amber-700 bg-amber-50/80 border border-amber-200/60 rounded-md p-2 mt-1.5">
                  ⚠️ Với Dynamic: Không bắt buộc nhập Base URL cố định. Test Base URL dùng để preview/test nhanh và tuyệt đối không tự dùng làm URL chạy production.
                </p>
              </div>
            )}

            <div>
              <label className="block text-xs font-semibold text-gray-700 mb-1.5">Description (Optional)</label>
              <textarea
                rows={2}
                value={siteDescInput}
                onChange={(e) => setSiteDescInput(e.target.value)}
                placeholder="Mô tả website..."
                className="w-full p-2.5 text-sm border border-gray-200 rounded-lg focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
              />
            </div>

            <div className="flex justify-end gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setSiteModalOpen(false)}
                className="h-9 px-3.5 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={isSavingSite}
                onClick={handleSaveSite}
                className="h-9 px-4 text-sm font-semibold bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg shadow-xs transition"
              >
                {isSavingSite ? 'Saving...' : 'Save Site'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
