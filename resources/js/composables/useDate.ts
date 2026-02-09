import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { formatInTimeZone, toZonedTime, fromZonedTime } from 'date-fns-tz';
import { 
    formatDistanceToNow, 
    isToday, 
    format, 
    isPast, 
    parseISO, 
    startOfWeek, 
    addDays, 
    isSameDay 
} from 'date-fns';

export function useDate() {
    const authStore = useAuthStore();
    
    const userTimezone = computed(() => {
        return authStore.user?.preferences?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    });

    /**
     * Helper to resolve "smart" format string based on date.
     */
    const getSmartFormat = (date: Date) => {
        return isToday(date) ? 'h:mm a' : 'MMM d';
    };

    /**
     * Format a date string or object according to the user's preferred timezone (Date only).
     */
    const formatDate = (dateValue: string | Date | number, formatStr: string = 'MMM d, yyyy') => {
        if (!dateValue) return '';
        
        try {
            const date = typeof dateValue === 'string' ? parseISO(dateValue) : dateValue;
            
            if (formatStr === 'smart') {
                return formatInTimeZone(date, userTimezone.value, getSmartFormat(date));
            }
            
            return formatInTimeZone(date, userTimezone.value, formatStr);
        } catch (error) {
            console.error('[useDate] Error formatting date:', error);
            return 'Invalid Date';
        }
    };

    /**
     * Format a date string or object according to the user's preferred timezone (Date & Time).
     */
    const formatDateTime = (dateValue: string | Date | number, formatStr: string = 'MMM d, yyyy, h:mm a') => {
        if (!dateValue) return '';
        
        try {
            const date = typeof dateValue === 'string' ? parseISO(dateValue) : dateValue;
            
            if (formatStr === 'smart') {
                return formatInTimeZone(date, userTimezone.value, getSmartFormat(date));
            }
            
            return formatInTimeZone(date, userTimezone.value, formatStr);
        } catch (error) {
            console.error('[useDate] Error formatting date-time:', error);
            return 'Invalid Date';
        }
    };

    /**
     * Format relative time (e.g., "5 minutes ago").
     */
    const formatRelativeTime = (dateValue: string | Date | number) => {
        if (!dateValue) return '';
        
        try {
            const date = typeof dateValue === 'string' ? parseISO(dateValue) : dateValue;
            return formatDistanceToNow(date, { addSuffix: true });
        } catch (error) {
            return 'Unknown Date';
        }
    };

    return {
        userTimezone,
        formatDate,
        formatDateTime,
        formatRelativeTime,
        toZonedTime,
        fromZonedTime,
        format,
        isPast,
        parseISO,
        startOfWeek,
        addDays,
        isSameDay,
        isToday,
        formatDistanceToNow
    };
}
