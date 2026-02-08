import { ref } from 'vue';

const CACHE_NAME = 'email-images-v1';

export function useImageCache() {
    const loading = ref(false);

    /**
     * Get a cached image URL (blob) or fetch it and cache it.
     */
    async function getCachedImage(url: string): Promise<string> {
        if (!url || url.startsWith('blob:') || url.startsWith('data:')) {
            return url;
        }

        try {
            const cache = await caches.open(CACHE_NAME);
            const cachedResponse = await cache.match(url);

            if (cachedResponse) {
                const blob = await cachedResponse.blob();
                return URL.createObjectURL(blob);
            }

            // Not in cache, fetch it
            loading.value = true;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`Failed to fetch image: ${response.statusText}`);
            }

            // Put a clone in cache
            await cache.put(url, response.clone());
            
            const blob = await response.blob();
            return URL.createObjectURL(blob);
        } catch (error) {
            console.error('Image caching error:', error);
            return url; // Fallback to original URL
        } finally {
            loading.value = false;
        }
    }

    /**
     * Clear the entire image cache
     */
    async function clearCache() {
        return await caches.delete(CACHE_NAME);
    }

    return {
        getCachedImage,
        clearCache,
        loading
    };
}
