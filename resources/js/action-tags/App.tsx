import React, { useState, useEffect } from 'react';
import { ActionRegistryInitialData, Site, PathItem, ActionItem, ActionTagItem, ScriptRequestItem } from './types';
import { ActionTagsScreen } from './ActionTagsScreen';
import { SitesPathsScreen } from './SitesPathsScreen';

interface AppProps {
  initialData: ActionRegistryInitialData;
  apiBase: string;
  csrfToken: string;
}

export const App: React.FC<AppProps> = ({ initialData, apiBase, csrfToken }) => {
  const [screen, setScreen] = useState<'tags' | 'sites_paths'>('tags');

  const [sites, setSites] = useState<Site[]>(initialData.sites || []);
  const [paths, setPaths] = useState<PathItem[]>(initialData.paths || []);
  const [actions, setActions] = useState<ActionItem[]>(initialData.actions || []);
  const [tags, setTags] = useState<ActionTagItem[]>(initialData.tags || []);
  const [requests, setRequests] = useState<ScriptRequestItem[]>(initialData.requests || []);

  const [notification, setNotification] = useState<{ message: string; type: 'success' | 'error' } | null>(null);

  const showToast = (message: string, type: 'success' | 'error' = 'success') => {
    setNotification({ message, type });
    setTimeout(() => setNotification(null), 3500);
  };

  const reloadData = async () => {
    try {
      const res = await fetch(`${apiBase}/data`, {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      if (res.ok) {
        const json = await res.json();
        if (json.ok) {
          setSites(json.sites);
          setPaths(json.paths);
          setActions(json.actions);
          setTags(json.tags);
          setRequests(json.requests);
        }
      }
    } catch (e) {
      console.error('Failed to reload action registry data', e);
    }
  };

  const handleCreateTag = async (data: {
    site_id: number;
    path_id: number;
    action_key: string;
  }) => {
    const res = await fetch(`${apiBase}/tags`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(data),
    });

    const json = await res.json();
    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Không thể tạo Action Tag');
    }

    showToast(`Đã tạo Action Tag [${json.tag.tag_key}] và tự động tạo Script Request!`);
    await reloadData();
  };

  const handleDeleteTag = async (tagId: number, tagKey: string) => {
    if (!window.confirm(`Bạn có chắc muốn xóa Action Tag [${tagKey}] không?`)) {
      return;
    }
    try {
      const res = await fetch(`${apiBase}/tags/${tagId}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const json = await res.json();
      if (!res.ok || !json.ok) {
        throw new Error(json.error || 'Không thể xóa Action Tag');
      }

      showToast(`Đã xóa Action Tag [${tagKey}] thành công!`);
      await reloadData();
    } catch (e: any) {
      showToast(e.message || 'Lỗi khi xóa Action Tag', 'error');
    }
  };

  const handleSaveSite = async (siteData: {
    id?: number;
    name: string;
    base_url?: string;
    base_url_mode?: 'fixed' | 'dynamic';
    test_base_url?: string;
    description?: string;
  }) => {
    const isEdit = typeof siteData.id === 'number';
    const url = isEdit ? `${apiBase}/sites/${siteData.id}` : `${apiBase}/sites`;
    const method = isEdit ? 'PUT' : 'POST';

    const res = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(siteData),
    });

    const json = await res.json();
    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Không thể lưu Site');
    }

    showToast(`Đã lưu Site [${json.site.name}] thành công!`);
    await reloadData();
  };

  const handleSavePath = async (pathData: {
    id?: number;
    site_id: number;
    name: string;
    url_pattern: string;
    description?: string;
  }) => {
    const isEdit = typeof pathData.id === 'number';
    const url = isEdit ? `${apiBase}/paths/${pathData.id}` : `${apiBase}/paths`;
    const method = isEdit ? 'PUT' : 'POST';

    const res = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(pathData),
    });

    const json = await res.json();
    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Không thể lưu Path');
    }

    showToast(`Đã lưu Path [${json.path.name}] thành công!`);
    await reloadData();
  };

  return (
    <div className="w-full relative text-gray-900">
      {/* Toast Notification */}
      {notification && (
        <div className="fixed bottom-6 right-6 z-50 animate-in fade-in slide-in-from-bottom-4 duration-200">
          <div
            className={`px-4 py-2.5 rounded-xl shadow-lg border text-sm font-medium flex items-center gap-2 ${
              notification.type === 'success'
                ? 'bg-emerald-800 text-white border-emerald-700'
                : 'bg-red-800 text-white border-red-700'
            }`}
          >
            <span>{notification.type === 'success' ? '✓' : '⚠'}</span>
            <span>{notification.message}</span>
          </div>
        </div>
      )}

      {screen === 'tags' ? (
        <ActionTagsScreen
          sites={sites}
          paths={paths}
          actions={actions}
          tags={tags}
          onOpenSitesPaths={() => setScreen('sites_paths')}
          onCreateTag={handleCreateTag}
          onDeleteTag={handleDeleteTag}
        />
      ) : (
        <SitesPathsScreen
          sites={sites}
          paths={paths}
          onBackToTags={() => setScreen('tags')}
          onSaveSite={handleSaveSite}
          onSavePath={handleSavePath}
        />
      )}
    </div>
  );
};
